<?php
namespace Neos\Node\Routing;

/*
 * This file is part of the TYPO3.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Node\Routing\Node\BasicDimensionHandler;
use Neos\Node\Routing\Node\RequestedNodeContextHolder;
use Neos\Node\Routing\Node\NodeUriPathRoutePartHandler;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Log\SystemLoggerInterface;
use TYPO3\Flow\Mvc\Routing\DynamicRoutePart;
use TYPO3\Flow\Property\PropertyMapper;
use TYPO3\Flow\Security\Context;
use TYPO3\Neos\Domain\Service\ContentContext;
use TYPO3\Neos\Routing\Exception\NoHomepageException;
use TYPO3\Neos\Routing\Exception\NoSiteException;
use TYPO3\Neos\Routing\Exception\NoSiteNodeException;
use TYPO3\Neos\Routing\Exception\NoSuchNodeException;
use TYPO3\Neos\Routing\Exception\NoWorkspaceException;
use TYPO3\Neos\Routing\FrontendNodeRoutePartHandlerInterface;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Utility\NodePaths;

/**
 * A route part handler for finding nodes specifically in the website's frontend.
 */
class FrontendNodeRoutePartHandler extends DynamicRoutePart implements FrontendNodeRoutePartHandlerInterface
{

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var NodeUriPathRoutePartHandler
     */
    protected $uriPathHandler;

    /**
     * @Flow\Inject
     * @var BasicDimensionHandler
     */
    protected $dimensionHandler;

    /**
     * @Flow\Inject
     * @var RequestedNodeContextHolder
     */
    protected $requestedNodeContextHolder;

    /**
     * @Flow\Inject
     * @var PropertyMapper
     */
    protected $propertyMapper;

    /**
     * @param string $partName
     */
    public function setName($partName)
    {
        parent::setName($partName);
        $this->dimensionHandler->setName($this->getName() . '.context.dimensions');
        $this->uriPathHandler->setName($this->getName());
    }

    /**
     * @param string $routePath
     * @return string
     */
    protected function findValueToMatch($routePath)
    {
        $splitStringPosition = false;
        if ($this->splitString !== '' && ($this->splitString !== '/' || $this->allowPartialEvaluation() === false)) {
            $splitStringPosition = strpos($routePath, $this->splitString);
        }

        if ($splitStringPosition !== false) {
            $routePath = substr($routePath, 0, $splitStringPosition);
        }

        if ($this->allowPartialEvaluation() === true) {
            $originalRoutePath = $routePath;
            $this->matchInternal($routePath);
            $routePath = substr($originalRoutePath, 0, -1 * strlen($routePath));
        }

        return $routePath;
    }

    /**
     * Matches a frontend URI pointing to a node (for example a page).
     *
     * This function tries to find a matching node by the given request path. If one was found, its
     * absolute context node path is set in $this->value and true is returned.
     *
     * Note that this matcher does not check if access to the resolved workspace or node is allowed because at the point
     * in time the route part handler is invoked, the security framework is not yet fully initialized.
     *
     * @param string $requestPath The request path (without leading "/", relative to the current Site Node)
     * @return boolean true if the $requestPath could be matched, otherwise false
     * @throws \Exception
     */
    protected function matchValue($requestPath)
    {
        return $this->matchInternal($requestPath);
    }

    /**
     * @param $requestPath
     * @return boolean
     * @throws NoHomepageException
     */
    protected function matchInternal(&$requestPath)
    {
        if ($this->value !== null) {
            return true;
        }

        $nodeContextPath = null;
        try {
            // Build context explicitly without authorization checks because the security context isn't available yet
            // anyway and any Entity Privilege targeted on Workspace would fail at this point:
            $this->securityContext->withoutAuthorizationChecks(function () use (&$nodeContextPath, $requestPath) {
                $nodeContextPath = $this->convertRequestPathToContextPath($requestPath);
            });
        } catch (\Exception $exception) {
            $this->systemLogger->log('FrontendNodeRoutePartHandler matchValue(): ' . $exception->getMessage(), LOG_DEBUG);
            if ($requestPath === '') {
                throw new NoHomepageException('Homepage could not be loaded. Probably you haven\'t imported a site yet', 1346950755, $exception);
            }

            return false;
        }

        if ($nodeContextPath === null) {
            return false;
        }

        $this->value = $nodeContextPath;

        return true;
    }

    /**
     * Returns the initialized node that is referenced by $requestPath, based on the node's
     * "uriPathSegment" property.
     *
     * Note that $requestPath will be modified (passed by reference) by buildContextFromRequestPath().
     *
     * @param string $requestPath The request path, for example /the/node/path@some-workspace
     * @return string a Node context path
     * @throws \TYPO3\Neos\Routing\Exception\NoWorkspaceException
     * @throws \TYPO3\Neos\Routing\Exception\NoSiteException
     * @throws \TYPO3\Neos\Routing\Exception\NoSuchNodeException
     * @throws \TYPO3\Neos\Routing\Exception\NoSiteNodeException
     * @throws \TYPO3\Neos\Routing\Exception\InvalidRequestPathException
     */
    protected function convertRequestPathToContextPath(&$requestPath)
    {
        $contentContext = $this->buildContextFromRequestPath($requestPath);
        $requestPathWithoutContext = $this->removeContextFromPath($requestPath);

        $workspace = $contentContext->getWorkspace();
        if ($workspace === null) {
            throw new NoWorkspaceException(sprintf('No workspace found for request path "%s"', $requestPath), 1346949318);
        }

        $site = $contentContext->getCurrentSite();
        if ($site === null) {
            throw new NoSiteException(sprintf('No site found for request path "%s"', $requestPath), 1346949693);
        }

        $siteNode = $contentContext->getCurrentSiteNode();
        if ($siteNode === null) {
            $currentDomain = $contentContext->getCurrentDomain() ? 'Domain with host pattern "' . $contentContext->getCurrentDomain()->getHostPattern() . '" matched.' : 'No specific domain matched.';
            throw new NoSiteNodeException(sprintf('No site node found for request path "%s". %s', $requestPath, $currentDomain), 1346949728);
        }

        $result = true;
        $contextPath = $siteNode->getContextPath();
        if ($requestPathWithoutContext !== '' && $this->onlyMatchSiteNodes()) {
            return null;
        }

        if ($requestPathWithoutContext !== '') {
            $result = $this->uriPathHandler->match($requestPathWithoutContext);
            $contextPath = $this->uriPathHandler->getValue();
            $requestPath = $requestPathWithoutContext;
        }
        if ($result === false) {
            throw new NoSuchNodeException(sprintf('No node found on request path "%s"', $requestPath), 1346949857);
        }

        return $contextPath;
    }

    /**
     * Checks, whether given value is a Node object and if so, sets $this->value to the respective node path.
     *
     * In order to render a suitable frontend URI, this function strips off the path to the site node and only keeps
     * the actual node path relative to that site node. In practice this function would set $this->value as follows:
     *
     * absolute node path: /sites/neostypo3org/homepage/about
     * $this->value:       homepage/about
     *
     * absolute node path: /sites/neostypo3org/homepage/about@user-admin
     * $this->value:       homepage/about@user-admin
     *
     * @param mixed $node Either a Node object or an absolute context node path
     * @return boolean true if value could be resolved successfully, otherwise false.
     */
    protected function resolveValue($node)
    {
        if (is_string($node)) {
            $node = $this->buildNodeFromContextPath($node);
        }

        if (!$node instanceof NodeInterface) {
            return false;
        }

        $contentContext = $node->getContext();

        if ($this->onlyMatchSiteNodes() && $contentContext instanceof ContentContext && $node !== $contentContext->getCurrentSiteNode()) {
            return false;
        }

        $routePath = $this->resolveRoutePathForNode($node);
        $this->value = $routePath;

        return true;
    }

    /**
     * Creates a content context from the given request path, considering possibly mentioned content dimension values.
     *
     * @param string &$requestPath The request path. If at least one content dimension is configured, the first path segment will identify the content dimension values
     * @return ContentContext The built content context
     */
    protected function buildContextFromRequestPath(&$requestPath)
    {
        $workspaceName = 'live';
        $result = $this->dimensionHandler->match($requestPath);

        // This is a workaround as NodePaths::explodeContextPath() (correctly)
        // expects a context path to have something before the '@', but the requestPath
        // could potentially contain only the context information.
        if (strpos($requestPath, '@') === 0) {
            $requestPath = '/' . $requestPath;
        }

        if ($requestPath !== '' && NodePaths::isContextPath($requestPath)) {
            try {
                $nodePathAndContext = NodePaths::explodeContextPath($requestPath);
                $workspaceName = $nodePathAndContext['workspaceName'];
            } catch (\InvalidArgumentException $exception) {
            }
        }

        $this->requestedNodeContextHolder->setPropertyByPath('workspaceName', $workspaceName);
        return $this->buildContextFromWorkspaceNameAndDimensions();
    }

    /**
     * @param string $path an absolute or relative node path which possibly contains context information, for example "/sites/somesite/the/node/path@some-workspace"
     * @return string the same path without context information
     */
    protected function removeContextFromPath($path)
    {
        $path = ltrim($path, '/');
        if ($path === '' || NodePaths::isContextPath($path) === false) {
            return $path;
        }
        try {
            $nodePathAndContext = NodePaths::explodeContextPath($path);
            // This is a workaround as we potentially prepend the context path with "/" in buildContextFromRequestPath to create a valid context path,
            // the code in this class expects an empty nodePath though for the site node, so we remove it again at this point.
            return $nodePathAndContext['nodePath'] === '/' ? '' : $nodePathAndContext['nodePath'];
        } catch (\InvalidArgumentException $exception) {
        }

        return null;
    }

    /**
     * Whether the current route part should only match/resolve site nodes (e.g. the homepage)
     *
     * @return boolean
     */
    protected function onlyMatchSiteNodes()
    {
        return isset($this->options['onlyMatchSiteNodes']) && $this->options['onlyMatchSiteNodes'] === true;
    }

    /**
     * Resolves the request path, also known as route path, identifying the given node.
     *
     * A path is built, based on the uri path segment properties of the parents of and the given node itself.
     * If content dimensions are configured, the first path segment will the identifiers of the dimension
     * values according to the current context.
     *
     * @param NodeInterface $node The node where the generated path should lead to
     * @return string The relative route path, possibly prefixed with a segment for identifying the current content dimension values
     */
    protected function resolveRoutePathForNode(NodeInterface $node)
    {
        $nodeContextPath = $node->getContextPath();
        $nodeContextPathSuffix = ($node->getContext()->getWorkspaceName() !== 'live') ? substr($nodeContextPath, strpos($nodeContextPath, '@')) : '';

        $routeValues = [$this->getName() => $node];
        $this->dimensionHandler->resolve($routeValues);
        $dimensionsUriSegment = $this->dimensionHandler->getValue();
        $this->uriPathHandler->resolve($routeValues);
        $requestPath = $this->uriPathHandler->getValue();

        return trim($dimensionsUriSegment . '/' . $requestPath, '/') . $nodeContextPathSuffix;
    }

    /**
     * Sets context properties like "invisibleContentShown" according to the workspace (live or not) and returns a
     * ContentContext object.
     *
     * @return ContentContext
     */
    protected function buildContextFromWorkspaceNameAndDimensions()
    {
        $contextProperties = $this->requestedNodeContextHolder->getContextProperties();
        if ($contextProperties['workspaceName'] !== 'live') {
            $this->requestedNodeContextHolder->setPropertyByPath('invisibleContentShown', true);
            $this->requestedNodeContextHolder->setPropertyByPath('inaccessibleContentShown', true);
        }

        return $this->requestedNodeContextHolder->getContext();
    }

    /**
     * @param string $contextPath
     * @return NodeInterface|null
     */
    protected function buildNodeFromContextPath($contextPath)
    {
        return $this->propertyMapper->convert($contextPath, NodeInterface::class);
    }

    /**
     * Was the "allowPartialEvaluation" option set and is true.
     *
     * @return boolean
     */
    protected function allowPartialEvaluation()
    {
        $options = $this->getOptions();
        if (isset($options['allowPartialEvaluation']) && $options['allowPartialEvaluation'] === true) {
            return true;
        }

        return false;
    }

    /**
     * @param array $options
     * @return void
     */
    public function setOptions(array $options)
    {
        parent::setOptions($options);
        $this->dimensionHandler->setOptions($options);
        $this->uriPathHandler->setOptions($options);
    }
}
