<?php
namespace TYPO3\TYPO3CR\Domain\Model;
use TYPO3\TYPO3CR\Exception\NodeExistsException;


/**
 * The node data inside the content repository. This is only a data
 * container that could be exchanged in the future.
 *
 * INTERNAL INTERFACE FOR NOW, SUBJECT TO CHANGE.
 *
 */
interface NodeDataInterface extends SimpleNodeDataContainerInterface {

	/**
	 * Returns the path of this node
	 *
	 * Example: /sites/mysitecom/homepage/about
	 *
	 * @return string The absolute node path
	 */
	public function getPath();

	/**
	 * Returns the level at which this node is located.
	 * Counting starts with 0 for "/", 1 for "/foo", 2 for "/foo/bar" etc.
	 *
	 * @return integer
	 */
	public function getDepth();

	/**
	 * Sets the workspace of this node.
	 *
	 *
	 * @param \TYPO3\TYPO3CR\Domain\Model\Workspace $workspace
	 * @return void
	 */
	public function setWorkspace(Workspace $workspace = NULL);

	/**
	 * Returns the workspace this node is contained in
	 *
	 * @return \TYPO3\TYPO3CR\Domain\Model\Workspace
	 */
	public function getWorkspace();

	/**
	 * Sets the index of this node
	 *
	 * @param integer $index The new index
	 * @return void
	 */
	public function setIndex($index);

	/**
	 * Returns the index of this node which determines the order among siblings
	 * with the same parent node.
	 *
	 * @return integer
	 */
	public function getIndex();

	/**
	 * Returns the parent node of this node
	 *
	 * @return \TYPO3\TYPO3CR\Domain\Model\NodeData The parent node or NULL if this is the root node
	 */
	public function getParent();

	/**
	 * Returns the parent node path
	 *
	 * @return string Absolute node path of the parent node
	 */
	public function getParentPath();

	/**
	 * Creates, adds and returns a child node of this node, without setting default
	 * properties or creating subnodes.
	 *
	 * @param string $name Name of the new node
	 * @param \TYPO3\TYPO3CR\Domain\Model\NodeType $nodeType Node type of the new node (optional)
	 * @param string $identifier The identifier of the node, unique within the workspace, optional(!)
	 * @param \TYPO3\TYPO3CR\Domain\Model\Workspace $workspace
	 * @param array $dimensions An array of dimension name to dimension values
	 * @throws NodeExistsException if a node with this path already exists.
	 * @throws \InvalidArgumentException if the node name is not accepted.
	 * @return \TYPO3\TYPO3CR\Domain\Model\NodeData
	 */
	public function createNodeData($name, NodeType $nodeType = NULL, $identifier = NULL, Workspace $workspace = NULL, array $dimensions = NULL);

	/**
	 * Change the identifier of this node data
	 *
	 * NOTE: This is only used for some very rare cases (to replace existing instances when moving).
	 *
	 * @param string $identifier
	 * @return void
	 */
	public function setIdentifier($identifier);

	/**
	 * Returns the number of child nodes a similar getChildNodes() call would return.
	 *
	 * @param string $nodeTypeFilter If specified, only nodes with that node type are considered
	 * @param Workspace $workspace
	 * @param array $dimensions
	 * @return integer The number of child nodes
	 */
	public function getNumberOfChildNodes($nodeTypeFilter = NULL, Workspace $workspace, array $dimensions);

	/**
	 * Removes this node and all its child nodes. This is an alias for setRemoved(TRUE)
	 *
	 * @return void
	 */
	public function remove();

	/**
	 * Enables using the remove method when only setters are available
	 *
	 * @param boolean $removed If TRUE, this node and it's child nodes will be removed. This can handle FALSE as well.
	 * @return void
	 */
	public function setRemoved($removed);

	/**
	 * If this node is a removed node.
	 *
	 * @return boolean
	 */
	public function isRemoved();

	/**
	 * Tells if this node is "visible".
	 *
	 * For this the "hidden" flag and the "hiddenBeforeDateTime" and "hiddenAfterDateTime" dates are taken into account.
	 * The fact that a node is "visible" does not imply that it can / may be shown to the user. Further modifiers
	 * such as isAccessible() need to be evaluated.
	 *
	 * @return boolean
	 */
	public function isVisible();

	/**
	 * Tells if this node may be accessed according to the current security context.
	 *
	 * @return boolean
	 */
	public function isAccessible();

	/**
	 * Tells if a node, in general, has access restrictions, independent of the
	 * current security context.
	 *
	 * @return boolean
	 */
	public function hasAccessRestrictions();

	/**
	 * Internal use, do not retrieve collection directly
	 *
	 * @return array<NodeDimension>
	 */
	public function getDimensions();

	/**
	 * Internal use, do not manipulate collection directly
	 *
	 * @param array <NodeDimension> $dimensionsToBeSet
	 * @return void
	 */
	public function setDimensions(array $dimensionsToBeSet);

	/**
	 * Make the node "similar" to the given source node. That means,
	 *  - all properties
	 *  - index
	 *  - node type
	 *  - content object
	 * will be set to the same values as in the source node.
	 *
	 * @param \TYPO3\TYPO3CR\Domain\Model\SimpleNodeDataContainerInterface $sourceNode
	 * @param boolean $isCopy
	 * @return void
	 */
	public function similarize(SimpleNodeDataContainerInterface $sourceNode, $isCopy = FALSE);

	/**
	 * Returns the dimensions and their values.
	 *
	 * @return array
	 */
	public function getDimensionValues();

	/**
	 * Get a unique string for all dimension values
	 *
	 * Internal method
	 *
	 * @return string
	 */
	public function getDimensionsHash();

	/**
	 * Checks if this instance matches the given workspace and dimensions.
	 *
	 * @param Workspace $workspace
	 * @param array $dimensions
	 * @return boolean
	 */
	public function matchesWorkspaceAndDimensions($workspace, array $dimensions = NULL);

	/**
	 * Check if this NodeData object is a purely internal technical object (like a shadow node).
	 * An internal NodeData should never produce a Node object.
	 *
	 * @return boolean
	 */
	public function isInternal();

	/**
	 * Move this NodeData to the given path and workspace.
	 *
	 * Basically 4 scenarios have to be covered here, depending on:
	 *
	 * - Does the NodeData have to be materialized (adapted to the workspace or target dimension)?
	 * - Does a shadow node exist on the target path?
	 *
	 * Because unique key constraints and Doctrine ORM don't support arbitrary removal and update combinations,
	 * existing NodeData instances are re-used and the metadata and content is swapped around.
	 *
	 * @param string $path
	 * @param Workspace $workspace
	 * @return NodeData If a shadow node was created this is the new NodeData object after the move.
	 */
	public function move($path, $workspace);
}