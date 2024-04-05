<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\Projection\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeAggregatesTypeIsAmbiguous;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamState;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\EventStore\Model\EventStream\MaybeVersion;

/**
 * This is a read API provided for constraint checks within the write side.
 * It must be bound to a contentStreamId and workspaceName on creation.
 *
 * @internal only for consumption in command handlers
 */
interface ContentGraphAdapterInterface
{
    /*
     * NODE AGGREGATES
     */

    public function rootNodeAggregateWithTypeExists(
        ContentStreamId $contentStreamId,
        NodeTypeName $nodeTypeName
    ): bool;

    /**
     * @return iterable<NodeAggregate>
     */
    public function findParentNodeAggregates(
        ContentStreamId $contentStreamId,
        WorkspaceName $workspaceName,
        NodeAggregateId $childNodeAggregateId
    ): iterable;

    /**
     * @throws NodeAggregatesTypeIsAmbiguous
     */
    public function findNodeAggregateById(
        ContentStreamId $contentStreamId,
        WorkspaceName $workspaceName,
        NodeAggregateId $nodeAggregateId
    ): ?NodeAggregate;

    public function findParentNodeAggregateByChildOriginDimensionSpacePoint(
        ContentStreamId $contentStreamId,
        WorkspaceName $workspaceName,
        NodeAggregateId $childNodeAggregateId,
        OriginDimensionSpacePoint $childOriginDimensionSpacePoint
    ): ?NodeAggregate;

    /**
     * @return iterable<NodeAggregate>
     */
    public function findChildNodeAggregates(
        ContentStreamId $contentStreamId,
        WorkspaceName $workspaceName,
        NodeAggregateId $parentNodeAggregateId
    ): iterable;

    /**
     * @return iterable<NodeAggregate>
     */
    public function findTetheredChildNodeAggregates(
        ContentStreamId $contentStreamId,
        WorkspaceName $workspaceName,
        NodeAggregateId $parentNodeAggregateId
    ): iterable;

    /**
     */
    public function getDimensionSpacePointsOccupiedByChildNodeName(
        ContentStreamId $contentStreamId,
        NodeName $nodeName,
        NodeAggregateId $parentNodeAggregateId,
        OriginDimensionSpacePoint $parentNodeOriginDimensionSpacePoint,
        DimensionSpacePointSet $dimensionSpacePointsToCheck
    ): DimensionSpacePointSet;

    /**
     * A node aggregate may have multiple child node aggregates with the same name
     * as long as they do not share dimension space coverage
     *
     * @return iterable<NodeAggregate>
     */
    public function findChildNodeAggregatesByName(
        ContentStreamId $contentStreamId,
        WorkspaceName $workspaceName,
        NodeAggregateId $parentNodeAggregateId,
        NodeName $name
    ): iterable;

    /*
     * NODES, basically anything you would ask a subgraph
     */

    /**
     * Does the subgraph with the provided identity contain any nodes
     */
    public function subgraphContainsNodes(
        ContentStreamId $contentStreamId,
        DimensionSpacePoint $dimensionSpacePoint
    ): bool;

    /**
     * Finds a specified node within a "subgraph"
     */
    public function findNodeInSubgraph(
        ContentStreamId $contentStreamId,
        WorkspaceName $workspaceName,
        DimensionSpacePoint $coveredDimensionSpacePoint,
        NodeAggregateId $nodeAggregateId
    ): ?Node;

    public function findParentNodeInSubgraph(
        ContentStreamId $contentStreamId,
        WorkspaceName $workspaceName,
        DimensionSpacePoint $coveredDimensionSpacePoint,
        NodeAggregateId $childNodeAggregateId
    ): ?Node;

    public function findChildNodeByNameInSubgraph(
        ContentStreamId $contentStreamId,
        WorkspaceName $workspaceName,
        DimensionSpacePoint $coveredDimensionSpacePoint,
        NodeAggregateId $parentNodeAggregateId,
        NodeName $nodeNamex
    ): ?Node;

    public function findPreceedingSiblingNodesInSubgraph(
        ContentStreamId $contentStreamId,
        WorkspaceName $workspaceName,
        DimensionSpacePoint $coveredDimensionSpacePoint,
        NodeAggregateId $startingSiblingNodeAggregateId
    ): Nodes;

    public function findSuceedingSiblingNodesInSubgraph(
        ContentStreamId $contentStreamId,
        WorkspaceName $workspaceName,
        DimensionSpacePoint $coveredDimensionSpacePoint,
        NodeAggregateId $startingSiblingNodeAggregateId
    ): Nodes;

    /*
     * CONTENT STREAMS
     */

    public function hasContentStream(
        ContentStreamId $contentStreamId
    ): bool;

    public function findStateForContentStream(
        ContentStreamId $contentStreamId
    ): ?ContentStreamState;

    public function findVersionForContentStream(
        ContentStreamId $contentStreamId
    ): MaybeVersion;

    /*
     * WORKSPACES
     */

    public function findWorkspaceByName(
        WorkspaceName $workspaceName
    ): ?Workspace;

    public function findWorkspaceByCurrentContentStreamId(
        ContentStreamId $contentStreamId
    ): ?Workspace;
}
