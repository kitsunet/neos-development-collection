<?php

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\Neos\Aspects;

use Neos\ContentRepository\NodeAccess\NodeAccessorManager;
use Neos\ContentRepository\Projection\Content\NodeInterface;
use Neos\ContentRepository\SharedModel\NodeAddressFactory;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Service\PluginService;

/**
 * @Flow\Scope("singleton")
 * @Flow\Aspect
 */
class PluginUriAspect
{
    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * The pluginService
     *
     * @var PluginService
     * @Flow\Inject
     */
    protected $pluginService;

    #[Flow\Inject]
    protected NodeAddressFactory $nodeAddressFactory;

    #[Flow\Inject]
    protected NodeAccessorManager $nodeAccessorManager;

    /**
     * @Flow\Around("method(Neos\Flow\Mvc\Routing\UriBuilder->uriFor())")
     * @param \Neos\Flow\Aop\JoinPointInterface $joinPoint The current join point
     * @return string The result of the target method if it has not been intercepted
     */
    public function rewritePluginViewUris(JoinPointInterface $joinPoint)
    {
        /** @var UriBuilder $uriBuilder */
        $uriBuilder = $joinPoint->getProxy();
        $request = $uriBuilder->getRequest();
        $arguments = $joinPoint->getMethodArguments();

        $currentNode = $request->getInternalArgument('__node');
        if (!$request->getMainRequest()->hasArgument('node') || !$currentNode instanceof NodeInterface) {
            return $joinPoint->getAdviceChain()->proceed($joinPoint);
        }

        $controllerObjectName = $this->getControllerObjectName($request, $arguments);
        $actionName = $arguments['actionName'] !== null
            ? $arguments['actionName']
            : $request->getControllerActionName();

        $targetNode = $this->pluginService->getPluginNodeByAction(
            $currentNode,
            $controllerObjectName,
            $actionName
        );

        $documentNode = null;
        if ($targetNode) {
            $nodeAccessor = $this->nodeAccessorManager->accessorFor(
                $targetNode->getContentStreamIdentifier(),
                $targetNode->getDimensionSpacePoint(),
                $targetNode->getVisibilityConstraints()
            );
            $documentNode = $targetNode;
            while ($documentNode instanceof NodeInterface) {
                if ($documentNode->getNodeTypeName()->equals(NodeTypeNameFactory::forDocument())) {
                    break;
                }
                $documentNode = $nodeAccessor->findParentNode($documentNode);
            }
        }

        return $documentNode ? $this->generateUriForNode($request, $joinPoint, $documentNode) : '';
    }

    /**
     * Merge the default plugin arguments of the Plugin with the arguments in the request
     * and generate a controllerObjectName
     *
     * @param ActionRequest $request
     * @param array<string,mixed> $arguments
     * @return string $controllerObjectName
     */
    public function getControllerObjectName($request, array $arguments)
    {
        $controllerName = $arguments['controllerName'] !== null
            ? $arguments['controllerName']
            : $request->getControllerName();
        $subPackageKey = $arguments['subPackageKey'] !== null
            ? $arguments['subPackageKey']
            : $request->getControllerSubpackageKey();
        $packageKey = $arguments['packageKey'] !== null
            ? $arguments['packageKey']
            : $request->getControllerPackageKey();

        $possibleObjectName = '@package\@subpackage\Controller\@controllerController';
        $possibleObjectName = str_replace('@package', str_replace('.', '\\', $packageKey), $possibleObjectName);
        $possibleObjectName = str_replace('@subpackage', $subPackageKey, $possibleObjectName);
        $possibleObjectName = str_replace('@controller', $controllerName, $possibleObjectName);
        $possibleObjectName = str_replace('\\\\', '\\', $possibleObjectName);

        $controllerObjectName = $this->objectManager->getCaseSensitiveObjectName($possibleObjectName);
        return $controllerObjectName ?: '';
    }

    /**
     * This method generates the Uri through the joinPoint with
     * temporary overriding the used node
     *
     * @param ActionRequest $request
     * @param JoinPointInterface $joinPoint The current join point
     * @param NodeInterface $node
     * @return string $uri
     */
    public function generateUriForNode(ActionRequest $request, JoinPointInterface $joinPoint, NodeInterface $node)
    {
        // store original node path to restore it after generating the uri
        $originalNodePath = $request->getMainRequest()->getArgument('node');
        $nodeAddress = $this->nodeAddressFactory->createFromNode($node);

        // generate the uri for the given node
        $request->getMainRequest()->setArgument('node', $nodeAddress->serializeForUri());
        $result = $joinPoint->getAdviceChain()->proceed($joinPoint);

        // restore the original node path
        $request->getMainRequest()->setArgument('node', $originalNodePath);

        return $result;
    }
}
