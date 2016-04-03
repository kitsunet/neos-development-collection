<?php
namespace Neos\Node\Routing\Node;

use Neos\Node\Routing\Exception\MissingUriPathSegmentException;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;

/**
 */
class UriPathResolver
{
    /**
     * @var string
     */
    protected $routeableNodeTypes = ['TYPO3.Neos:Document'];

    /**
     * @var string
     */
    protected $segmentSeparator = '/';

    /**
     * @param array $routeableNodeTypes
     */
    public function setRouteableNodeTypes($routeableNodeTypes)
    {
        $this->routeableNodeTypes = $routeableNodeTypes;
    }

    /**
     * @param string $segmentSeparator
     */
    public function setSegmentSeparator($segmentSeparator)
    {
        $this->segmentSeparator = $segmentSeparator;
    }

    /**
     * @param NodeInterface $startingPoint
     * @param $uriPath
     * @return NodeInterface|null
     */
    public function matchUriPath(NodeInterface $startingPoint, $uriPath)
    {
        $node = $startingPoint;
        foreach (explode($this->segmentSeparator, $uriPath) as $pathSegment) {
            $node = $this->findMatchingNode($node, $pathSegment);
        }

        return $node;
    }

    /**
     * Matches the given uriPath.
     *
     * @param NodeInterface $startingPoint
     * @param string $uriPath
     * @return array
     */
    public function matchUriPathAllowingPartialMatch(NodeInterface $startingPoint, $uriPath)
    {
        $node = $lastEvaluatedNode = $startingPoint;
        $evaluatedPath = '';

        $uriPath = ltrim($uriPath, '/');
        foreach (explode($this->segmentSeparator, $uriPath) as $pathSegment) {
            $node = $this->findMatchingNode($node, $pathSegment);
            if ($node === null) {
                break;
            }

            $lastEvaluatedNode = $node;
            $evaluatedPath .= $this->segmentSeparator . $pathSegment;
        }

        return [$lastEvaluatedNode, ltrim($evaluatedPath, $this->segmentSeparator)];
    }

    /**
     * Find a direct child of the given currentNode that can match the expected pathSegment.
     *
     * @param NodeInterface $currentNode
     * @param string $pathSegment
     * @return NodeInterface|null
     */
    protected function findMatchingNode(NodeInterface $currentNode, $pathSegment)
    {
        if ($currentNode === null) {
            return null;
        }

        foreach ($currentNode->getChildNodes(implode(',', $this->routeableNodeTypes)) as $node) {
            /** @var NodeInterface $node */
            if ($this->matchPathSegment($node, $pathSegment)) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Match the given path segment to the node.
     * This can be adapted with custom logic if other properties should
     * be used for the uri generation.
     *
     * @param NodeInterface $node
     * @param $pathSegment
     * @return boolean
     */
    protected function matchPathSegment(NodeInterface $node, $pathSegment)
    {
        $nodePathSegment = $node->getProperty('uriPathSegment');
        return ($nodePathSegment === $pathSegment);
    }

    /**
     * Generates a uri path for the given node, stopping at the optional $uriPathRoot or
     * at first node that is not of a routable node type.
     *
     * @param NodeInterface $node
     * @param NodeInterface|null $uriPathRoot
     * @return string
     * @throws MissingUriPathSegmentException
     */
    public function generateUriPathForNode(NodeInterface $node, NodeInterface $uriPathRoot = null)
    {
        $uriPathSegments = [];
        while ($node instanceof NodeInterface && $this->shouldContinueResolving($node, $uriPathRoot)) {

            $pathSegment = $this->resolvePathSegmentFor($node);
            $uriPathSegments[] = rawurlencode($pathSegment);
            $node = $node->getParent();
        }

        return implode($this->segmentSeparator, array_reverse($uriPathSegments));
    }

    /**
     * Checks if further parents should be visited to generate the uri path
     *
     * @param NodeInterface $currentNode
     * @param NodeInterface $endPointNode
     * @return boolean
     */
    protected function shouldContinueResolving(NodeInterface $currentNode, NodeInterface $endPointNode = null)
    {
        if ($endPointNode !== null) {
            return $this->nodeIsNotEndPoint($currentNode, $endPointNode);
        }

        return $this->nodeHasRoutableNodeType($currentNode);
    }

    /**
     * Resolve a path segment for the given node.
     * This can be extended with custom logic to use other properties to generate the node path.
     *
     * @param NodeInterface $node
     * @return mixed
     * @throws MissingUriPathSegmentException
     */
    protected function resolvePathSegmentFor(NodeInterface $node)
    {
        if (!$node->hasProperty('uriPathSegment')) {
            throw new MissingUriPathSegmentException(sprintf('Missing "uriPathSegment" property for node "%s". Nodes can be migrated with the "flow node:repair" command.', $node->getPath()), 1459242358);
        }

        return $node->getProperty('uriPathSegment');
    }

    /**
     * Checks if the given node has a routeable node type
     *
     * @param NodeInterface $node
     * @return boolean
     * @see generateNodePathFromNode ($continueResolvingCondition)
     */
    protected function nodeHasRoutableNodeType(NodeInterface $node)
    {
        $matchingNodeTypes = array_filter($this->routeableNodeTypes, function ($routeableNodeType) use ($node) {
            return $node->getNodeType()->isOfType($routeableNodeType);
        });

        if ($matchingNodeTypes === []) {
            return false;
        }

        return true;
    }

    /**
     * Checks if given node does not match the end point node for node uri paths
     *
     * @param NodeInterface $node
     * @param NodeInterface $endPointNode
     * @return boolean
     * @see generateNodePathFromNode ($continueResolvingCondition)
     */
    protected function nodeIsNotEndPoint(NodeInterface $node, NodeInterface $endPointNode)
    {
        return ($node !== $endPointNode);
    }
}
