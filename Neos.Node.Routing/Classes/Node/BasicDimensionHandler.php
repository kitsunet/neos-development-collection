<?php
namespace Neos\Node\Routing\Node;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Mvc\Routing\DynamicRoutePart;
use TYPO3\Flow\Reflection\ObjectAccess;
use TYPO3\Neos\Domain\Service\ContentContext;
use TYPO3\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use TYPO3\Neos\Routing\Exception\InvalidDimensionPresetCombinationException;
use TYPO3\Neos\Routing\Exception\InvalidRequestPathException;
use TYPO3\Neos\Routing\Exception\NoSuchDimensionValueException;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;

/**
 *
 */
class BasicDimensionHandler extends DynamicRoutePart
{
    const DIMENSION_REQUEST_PATH_MATCHER = '|^
        (?<firstUriPart>[^/@]+)                    # the first part of the URI, before the first slash, may contain the encoded dimension preset
        (?:                                        # start of non-capturing submatch for the remaining URL
            /?                                     # a "/"; optional. it must also match en@user-admin
            (?<remainingRequestPath>.*)            # the remaining request path
        )?                                         # ... and this whole remaining URL is optional
        $                                          # make sure we consume the full string
    |x';

    /**
     * @Flow\Inject
     * @var RequestedNodeContextHolder
     */
    protected $requestedNodeContextHolder;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @Flow\InjectConfiguration(package="TYPO3.Neos", path="routing.supportEmptySegmentForDimensions")
     * @var boolean
     */
    protected $supportEmptySegmentForDimensions;

    /**
     * Returns the first part of $routePath.
     * If a split string is set, only the first part of the value until location of the splitString is returned.
     * This method can be overridden by custom RoutePartHandlers to implement custom matching mechanisms.
     *
     * @param string $routePath The request path to be matched
     * @return string value to match, or an empty string if $routePath is empty or split string was not found
     * @api
     */
    protected function findValueToMatch($routePath)
    {
        $matches = [];
        preg_match(self::DIMENSION_REQUEST_PATH_MATCHER, $routePath, $matches);
        if (!isset($matches['firstUriPart'])) {
            return null;
        }

        return $matches['firstUriPart'];
    }

    /**
     * Checks, whether given value can be matched.
     * In the case of default Dynamic Route Parts a value matches when it's not empty.
     * This method can be overridden by custom RoutePartHandlers to implement custom matching mechanisms.
     *
     * @param string $value value to match
     * @return boolean TRUE if value could be matched successfully, otherwise FALSE.
     * @api
     */
    protected function matchValue($value)
    {
        $result = $this->parseDimensionsAndNodePathFromRequestPath($value);
        if ($result === false) {
            return false;
        }

        foreach ($this->value as $dimensionName => $dimensionValues) {
            $this->requestedNodeContextHolder->setPropertyByPath('dimensions.' . $dimensionName, $dimensionValues);
        }

        return true;
    }

    /**
     * Removes matching part from $routePath.
     * This method can be overridden by custom RoutePartHandlers to implement custom matching mechanisms.
     *
     * @param string $routePath The request path to be matched
     * @param string $valueToMatch The matching value
     * @return void
     * @api
     */
    protected function removeMatchingPortionFromRequestPath(&$routePath, $valueToMatch)
    {
        if ($valueToMatch !== null && $valueToMatch !== '') {
            $routePath = substr($routePath, strlen($valueToMatch));
        }
    }

    /**
     * Choose between default method for parsing dimensions or the one which allows uriSegment to be empty for default preset.
     *
     * @param string $valueToMatch The request path currently being processed by this route part handler, e.g. "de_global/startseite/ueber-uns"
     * @return string the request path after removing matching dimensions
     */
    protected function parseDimensionsAndNodePathFromRequestPath($valueToMatch)
    {
        if ($this->supportEmptySegmentForDimensions) {
            $result = $this->parseDimensionsAndNodePathFromRequestPathAllowingEmptySegment($valueToMatch);
        } else {
            $result = $this->parseDimensionsAndNodePathFromRequestPathAllowingNonUniqueSegment($valueToMatch);
        }

        return $result;
    }

    /**
     * Parses the given request path and checks if the first path segment is one or a set of content dimension preset
     * identifiers. If that is the case, the return value is an array of dimension names and their preset URI segments.
     * Allows uriSegment to be empty for default dimension preset.
     *
     * If the first path segment contained content dimension information, it is removed from &$requestPath.
     *
     * @param string &$valueToMatch The request path currently being processed by this route part handler, e.g. "de_global/startseite/ueber-uns"
     * @return array An array of dimension name => dimension values (array of string)
     * @throws InvalidDimensionPresetCombinationException
     */
    protected function parseDimensionsAndNodePathFromRequestPathAllowingEmptySegment($valueToMatch)
    {
        $dimensionPresets = $this->contentDimensionPresetSource->getAllPresets();
        if (count($dimensionPresets) === 0) {
            return [];
        }
        $dimensionsAndDimensionValues = [];

        $chosenDimensionPresets = $this->collectDefaultPresets();
        if (!empty($valueToMatch)) {
            $firstUriPartExploded = explode('_', $valueToMatch);
            foreach ($firstUriPartExploded as $uriSegment) {
                $uriSegmentIsValid = false;
                foreach ($dimensionPresets as $dimensionName => $dimensionPreset) {
                    $preset = $this->contentDimensionPresetSource->findPresetByUriSegment($dimensionName, $uriSegment);
                    if ($preset !== null) {
                        $uriSegmentIsValid = true;
                        $dimensionsAndDimensionValues[$dimensionName] = $preset['values'];
                        $chosenDimensionPresets[$dimensionName] = $preset['identifier'];
                        break;
                    }
                }
                if (!$uriSegmentIsValid) {
                    return false;
                }
            }
        }
        if (!$this->contentDimensionPresetSource->isPresetCombinationAllowedByConstraints($chosenDimensionPresets)) {
            throw new InvalidDimensionPresetCombinationException(sprintf('The resolved content dimension preset combination (%s) is invalid or restricted by content dimension constraints. Check your content dimension settings if you think that this is an error.', 'x'), 1428657721);
        }

        $this->value = $dimensionsAndDimensionValues;
        return true;
    }

    /**
     * Parses the given request path and checks if the first path segment is one or a set of content dimension preset
     * identifiers. If that is the case, the return value is an array of dimension names and their preset URI segments.
     * Doesn't allow empty uriSegment, but allows uriSegment to be not unique across presets.
     *
     * If the first path segment contained content dimension information, it is removed from &$requestPath.
     *
     * @param string &$valueToMatch The request path currently being processed by this route part handler, e.g. "de_global/startseite/ueber-uns"
     * @return array An array of dimension name => dimension values (array of string)
     * @throws InvalidDimensionPresetCombinationException
     * @throws InvalidRequestPathException
     * @throws NoSuchDimensionValueException
     */
    protected function parseDimensionsAndNodePathFromRequestPathAllowingNonUniqueSegment($valueToMatch)
    {
        $dimensionPresets = $this->contentDimensionPresetSource->getAllPresets();
        if (count($dimensionPresets) === 0) {
            return [];
        }

        $dimensionsAndDimensionValues = [];
        $chosenDimensionPresets = [];

        if (empty($valueToMatch)) {
            $chosenDimensionPresets = $this->collectDefaultPresets();
        } else {
            $firstUriPart = explode('_', $valueToMatch);

            if (count($firstUriPart) !== count($dimensionPresets)) {
                throw new InvalidRequestPathException(sprintf('The first path segment of the request URI (%s) does not contain the necessary content dimension preset identifiers for all configured dimensions. This might be an old URI which doesn\'t match the current dimension configuration anymore.', $valueToMatch), 1413389121);
            }

            foreach ($dimensionPresets as $dimensionName => $dimensionPreset) {
                $uriSegment = array_shift($firstUriPart);
                $preset = $this->contentDimensionPresetSource->findPresetByUriSegment($dimensionName, $uriSegment);
                if ($preset === null) {
                    throw new NoSuchDimensionValueException(sprintf('Could not find a preset for content dimension "%s" through the given URI segment "%s".', $dimensionName, $uriSegment), 1413389321);
                }
                $dimensionsAndDimensionValues[$dimensionName] = $preset['values'];
                $chosenDimensionPresets[$dimensionName] = $preset['identifier'];
            }
        }

        if (!$this->contentDimensionPresetSource->isPresetCombinationAllowedByConstraints($chosenDimensionPresets)) {
            throw new InvalidDimensionPresetCombinationException(sprintf('The resolved content dimension preset combination (%s) is invalid or restricted by content dimension constraints. Check your content dimension settings if you think that this is an error.', 'x'), 1428657721);
        }

        $this->value = $dimensionsAndDimensionValues;
        return true;
    }

    /**
     * @return array
     */
    protected function collectDefaultPresets() {
        $defaultPresets = [];
        $dimensionPresets = $this->contentDimensionPresetSource->getAllPresets();
        foreach ($dimensionPresets as $dimensionName => $dimensionPreset) {
            $dimensionsAndDimensionValues[$dimensionName] = $dimensionPreset['presets'][$dimensionPreset['defaultPreset']]['values'];
            $defaultPresets[$dimensionName] = $dimensionPreset['defaultPreset'];
        }

        return $defaultPresets;
    }

    /**
     * @param string $uriSegment
     * @return array|null
     */
    protected function findPresetForUriSegment($uriSegment)
    {
        $dimensionPresets = $this->contentDimensionPresetSource->getAllPresets();
        foreach (array_keys($dimensionPresets) as $dimensionName) {
            $preset = $this->contentDimensionPresetSource->findPresetByUriSegment($dimensionName, $uriSegment);
            if ($preset !== null) {
                return $preset;
            }
        }

        return null;
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
        // We assume this handler is called on a NodeInterface route argument as in {node.context.dimensions}
        $nameParts = explode('.', $this->name);
        array_pop($nameParts);
        array_pop($nameParts);
        $routeValuePathToNode = implode('.', $nameParts);

        $possibleNodeToRoute = ObjectAccess::getPropertyPath($routeValues, $routeValuePathToNode);
        return [$possibleNodeToRoute, ObjectAccess::getPropertyPath($routeValues, $this->name)];
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
        list($node, $dimensions) = $value;
        if ($dimensions === null) {
            return false;
        }

        $uriSegment = $this->getUriSegmentForDimensions($value, $this->nodeIsSiteNode($node));
        $this->value = rawurlencode($uriSegment);
        if ($this->lowerCase) {
            $this->value = strtolower($this->value);
        }

        return true;
    }

    /**
     * @param NodeInterface $node
     * @return boolean
     */
    protected function nodeIsSiteNode(NodeInterface $node = null)
    {
        $siteNode = null;
        // TODO define flexible routing "root" not hard bound to neos site
        if ($node instanceof NodeInterface) {
            $context = $node->getContext();
            if ($context instanceof ContentContext) {
                $siteNode = $context->getCurrentSiteNode();
            }
        }

        return ($siteNode !== null && $siteNode === $node);
    }

    /**
     * Find a URI segment in the content dimension presets for the given "language" dimension values
     *
     * This will do a reverse lookup from actual dimension values to a preset and fall back to the default preset if none
     * can be found.
     *
     * @param array $dimensionsValues An array of dimensions and their values, indexed by dimension name
     * @param boolean $currentNodeIsSiteNode If the current node is actually the site node
     * @return string
     * @throws \Exception
     */
    protected function getUriSegmentForDimensions(array $dimensionsValues, $currentNodeIsSiteNode)
    {
        $uriSegment = '';
        $allDimensionPresetsAreDefault = true;
        foreach ($this->contentDimensionPresetSource->getAllPresets() as $dimensionName => $dimensionPresets) {
            $preset = null;
            if (isset($dimensionsValues[$dimensionName])) {
                $preset = $this->contentDimensionPresetSource->findPresetByDimensionValues($dimensionName, $dimensionsValues[$dimensionName]);
            }
            $defaultPreset = $this->contentDimensionPresetSource->getDefaultPreset($dimensionName);
            if ($preset === null) {
                $preset = $defaultPreset;
            }
            if ($preset !== $defaultPreset) {
                $allDimensionPresetsAreDefault = false;
            }
            if (!isset($preset['uriSegment'])) {
                throw new \Exception(sprintf('No "uriSegment" configured for content dimension preset "%s" for dimension "%s". Please check the content dimension configuration in Settings.yaml', $preset['identifier'], $dimensionName), 1395824520);
            }
            $uriSegment .= $preset['uriSegment'] . '_';
        }

        if ($allDimensionPresetsAreDefault && $currentNodeIsSiteNode) {
            return '';
        } else {
            return trim($uriSegment, '_');
        }
    }
}
