<?php
namespace Neos\Node\Routing\Node;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Mvc\Routing\DynamicRoutePart;
use TYPO3\Flow\Reflection\ObjectAccess;
use TYPO3\Flow\Security\Context;
use TYPO3\Neos\Domain\Service\ContentContext;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;

/**
 * Can match/resolve uri paths and nodes.
 *
 * When the "allowPartialEvaluation" option was set then this route part handler will
 * match as much of the uriPath to nodes as possible, if no further matches are found
 * the last evaluated node becomes the  match result of this handler and the remaining
 * uriPath is available for further route part evaluation.
 */
class NodeUriPathRoutePartHandler extends DynamicRoutePart
{
    /**
     * @var string
     */
    protected $routeableNodeTypes = ['TYPO3.Neos:Document'];

    /**
     * @Flow\Inject
     * @var RequestedNodeContextHolder
     */
    protected $requestedNodeContextHolder;

    /**
     * @var NodeInterface
     */
    protected $evaluatedNode;

    /**
     * @Flow\Inject
     * @var UriPathResolver
     */
    protected $uriPathResolver;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    /**
     * @param array $routeableNodeTypes
     */
    public function setRouteableNodeTypes($routeableNodeTypes)
    {
        $this->routeableNodeTypes = $routeableNodeTypes;
    }

    /**
     * @param string $routePath
     * @return string
     */
    protected function findValueToMatch($routePath)
    {
        $splitStringPosition = false;
        if ($this->splitString !== '') {
            $splitStringPosition = strpos($routePath, $this->splitString);
        }

        if ($splitStringPosition !== false) {
            $routePath = substr($routePath, 0, $splitStringPosition);
        }

        if ($this->allowPartialEvaluation() === true) {
            $this->securityContext->withoutAuthorizationChecks(function () use (&$routePath) {
                $routePath = $this->evaluatePartialRoutePath($routePath);
            });
        }

        return $routePath;
    }

    /**
     * @param string $value
     * @return boolean
     */
    protected function matchValue($value)
    {
        if ($this->deferNodePathEvaluation()) {
            $this->value = $this;
            return true;
        }

        $node = $this->evaluatedNode;
        if ($node === null) {
            $this->securityContext->withoutAuthorizationChecks(function () use (&$node, $value) {
                $node = $this->evaluateRoutePath($value);
            });
        }

        if ($node === null) {
            return false;
        }

        $this->value = $node->getContextPath();
        return true;
    }

    /**
     * @param string $uriPath
     * @return string
     */
    protected function evaluatePartialRoutePath($uriPath)
    {
        /** @var ContentContext $context */
        $context = $this->requestedNodeContextHolder->getContext();
        $node = $context->getCurrentSiteNode();
        list($lastEvaluatedNode, $evaluatedPath) = $this->uriPathResolver->matchUriPathAllowingPartialMatch($node, $uriPath);
        $this->evaluatedNode = $lastEvaluatedNode;
        return $evaluatedPath;
    }

    /**
     * @param string $uriPath
     * @return null|NodeInterface
     */
    protected function evaluateRoutePath($uriPath)
    {
        /** @var ContentContext $context */
        $context = $this->requestedNodeContextHolder->getContext();
        $node = $context->getCurrentSiteNode();
        $evaluatedNode = $this->uriPathResolver->matchUriPath($node, $uriPath);
        $this->evaluatedNode = $evaluatedNode;

        return $evaluatedNode;
    }

    /**
     * Returns the route value of the current route part.
     * This method can be overridden by custom RoutePartHandlers to implement custom resolving mechanisms.
     *
     * @param array $routeValues An array with key/value pairs to be resolved by Dynamic Route Parts.
     * @return string|array value to resolve.
     * @api
     */
    protected function findValueToResolve(array $routeValues)
    {
        return ObjectAccess::getPropertyPath($routeValues, $this->name);
    }

    /**
     * Checks, whether given value can be resolved and if so, sets $this->value to the resolved value.
     * If $value is empty, this method checks whether a default value exists.
     * This method can be overridden by custom RoutePartHandlers to implement custom resolving mechanisms.
     *
     * @param mixed $value value to resolve
     * @return boolean TRUE if value could be resolved successfully, otherwise FALSE.
     * @api
     */
    protected function resolveValue($value)
    {
        /** @var NodeInterface $node */
        $node = $value;
        $endPointNode = null;
        if ($node->getContext() instanceof ContentContext) {
            $endPointNode = $node->getContext()->getCurrentSiteNode();
        }

        $this->value = $this->uriPathResolver->generateUriPathForNode($node, $endPointNode);

        if ($this->value === null) {
            return false;
        }

        if ($this->lowerCase) {
            $this->value = strtolower($this->value);
        }

        return true;
    }

    /**
     * Was the "deferredNodePathEvaluation" option set and is true.
     *
     * @return boolean
     */
    protected function deferNodePathEvaluation()
    {
        $options = $this->getOptions();
        if (isset($options['deferredNodePathEvaluation']) && $options['deferredNodePathEvaluation'] === true) {
            return true;
        }

        return false;
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
}
