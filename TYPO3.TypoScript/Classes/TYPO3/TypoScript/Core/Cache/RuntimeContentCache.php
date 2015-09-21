<?php
namespace TYPO3\TypoScript\Core\Cache;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "TYPO3.TypoScript".      *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cache\CacheAwareInterface;
use TYPO3\TypoScript\Core\Runtime;
use TYPO3\TypoScript\Exception;

/**
 * Integrate the ContentCache into the TypoScript Runtime
 *
 * Holds cache related runtime state.
 */
class RuntimeContentCache {

	/**
	 * @var Runtime
	 */
	protected $runtime;

	/**
	 * @var boolean
	 */
	protected $enableContentCache = FALSE;

	/**
	 * @var boolean
	 */
	protected $inCacheEntryPoint = NULL;

	/**
	 * @var boolean
	 */
	protected $addCacheSegmentMarkersToPlaceholders = FALSE;

	/**
	 * Stack of cached segment metadata (lifetime)
	 *
	 * @var array
	 */
	protected $cacheMetadata = array();

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TypoScript\Core\Cache\ContentCache
	 */
	protected $contentCache;

	/**
	 * @var \TYPO3\Flow\Property\PropertyMapper
	 * @Flow\Inject
	 */
	protected $propertyMapper;

	/**
	 * @param Runtime $runtime
	 */
	public function __construct(Runtime $runtime) {
		$this->runtime = $runtime;
	}

	/**
	 * Enter an evaluation
	 *
	 * Needs to be called right before evaluation of a path starts to check the cache mode and set internal state
	 * like the cache entry point.
	 *
	 * @param array $configuration
	 * @param string $typoScriptPath
	 * @return array An evaluate context array that needs to be passed to subsequent calls to pass the current state
	 * @throws \TYPO3\TypoScript\Exception
	 */
	public function enter(array $configuration, $typoScriptPath) {
		$cacheForPathEnabled = isset($configuration['mode']) && $configuration['mode'] === 'cached';
		$cacheForPathDisabled = isset($configuration['mode']) && $configuration['mode'] === 'uncached';

		$currentPathIsEntryPoint = FALSE;
		if ($this->enableContentCache && $cacheForPathEnabled) {
			if ($this->inCacheEntryPoint === NULL) {
				$this->inCacheEntryPoint = TRUE;
				$currentPathIsEntryPoint = TRUE;
			}
		}

		$contextVariables = array();
		if ($this->enableContentCache) {
			$contextArray = $this->runtime->getCurrentContext();
			if (isset($configuration['context'])) {
				foreach ($configuration['context'] as $contextVariableName) {
					$contextVariables[$contextVariableName] = $contextArray[$contextVariableName];
				}
			} else {
				$contextVariables = $contextArray;
			}
		}

		return array(
			'configuration' => $configuration,
			'typoScriptPath' => $typoScriptPath,
			'cacheForPathEnabled' => $cacheForPathEnabled,
			'cacheForPathDisabled' => $cacheForPathDisabled,
			'currentPathIsEntryPoint' => $currentPathIsEntryPoint,
			'contextVariables' => $contextVariables
		);
	}

	/**
	 * Check for cached evaluation and or collect metadata for evaluation
	 *
	 * Try to get a cached segment for the current path and return that with all uncached segments evaluated if it
	 * exists. Otherwise metadata for the cache lifetime is collected (if configured) for nested evaluations (to find the
	 * minimum maximumLifetime).
	 *
	 * @param array $evaluateContext The current evaluation context
	 * @param object $tsObject The current TypoScript object (for "this" in evaluations)
	 * @return array Cache hit state as boolean and value as mixed
	 */
	public function preEvaluate(array &$evaluateContext, $tsObject) {
		if ($this->enableContentCache) {
			if ($evaluateContext['cacheForPathEnabled']) {
				$evaluateContext['cacheIdentifierValues'] = $this->buildCacheIdentifierValues($evaluateContext['configuration'], $evaluateContext['typoScriptPath'], $tsObject);

				$self = $this;
				$segment = $this->contentCache->getCachedSegment(function($commandName, $commandArgument, $unserializedMetadata) use ($self) {
					$commandMethod = 'command' . ucfirst($commandName);
					if (is_callable(array($self, $commandMethod))) {
						return $self->$commandMethod($commandArgument, $unserializedMetadata);
					} else {
						throw new Exception(sprintf('Unknown cache command "%s"', $commandName), 1392837596);
					}
				}, $evaluateContext['typoScriptPath'], $evaluateContext['cacheIdentifierValues'], $this->addCacheSegmentMarkersToPlaceholders);
				if ($segment !== FALSE) {
					return array(TRUE, $segment);
				} else {
					$this->addCacheSegmentMarkersToPlaceholders = TRUE;
				}

				$this->cacheMetadata[] = array(
					'lifetime' => NULL
				);
			}

			if (isset($evaluateContext['configuration']['maximumLifetime'])) {
				$maximumLifetime = $this->runtime->evaluate($evaluateContext['typoScriptPath'] . '/__meta/cache/maximumLifetime', $tsObject);

				if ($maximumLifetime !== NULL && $this->cacheMetadata !== array()) {
					$cacheMetadata = &$this->cacheMetadata[count($this->cacheMetadata) - 1];
					$cacheMetadata['lifetime'] = $cacheMetadata['lifetime'] !== NULL ? min($cacheMetadata['lifetime'], $maximumLifetime) : $maximumLifetime;
				}
			}
		}
		return array(FALSE, NULL);
	}

	/**
	 * Post process output for caching information
	 *
	 * The content cache stores cache segments with markers inside the generated content. This method creates cache
	 * segments and will process the final outer result (currentPathIsEntryPoint) to remove all cache markers and
	 * store cache entries.
	 *
	 * @param array $evaluateContext The current evaluation context
	 * @param object $tsObject The current TypoScript object (for "this" in evaluations)
	 * @param mixed $output The generated output after caching information was removed
	 * @return mixed The post-processed output with cache segment markers or cleaned for the entry point
	 */
	public function postProcess(array $evaluateContext, $tsObject, $output) {
		if ($this->enableContentCache && $evaluateContext['cacheForPathEnabled']) {
			$cacheMetadata = array_pop($this->cacheMetadata);
			$metadata = array(
				'tags' => $this->buildCacheTags($evaluateContext['configuration'], $evaluateContext['typoScriptPath'], $tsObject),
				'lifetime' => $cacheMetadata['lifetime'],
				'context' => $this->serializeContext($evaluateContext['contextVariables']),
				'path' => $evaluateContext['typoScriptPath']
			);
			$output = $this->contentCache->createCacheSegment($output, $evaluateContext['cacheIdentifierValues'], $metadata);
		} elseif ($this->enableContentCache && $evaluateContext['cacheForPathDisabled'] && $this->inCacheEntryPoint) {
			$metadata = array(
				'context' => $this->serializeContext($evaluateContext['contextVariables'])
			);
			$output = $this->contentCache->createSegment($output, ContentCache::CACHE_COMMAND_UNCACHED, $evaluateContext['typoScriptPath'], $metadata);
		}

		if ($evaluateContext['cacheForPathEnabled'] && $evaluateContext['currentPathIsEntryPoint']) {
			$output = $this->contentCache->processCacheSegments($output, $this->enableContentCache);
			$this->inCacheEntryPoint = NULL;
			$this->addCacheSegmentMarkersToPlaceholders = FALSE;
		}

		return $output;
	}

	/**
	 * Leave the evaluation of a path
	 *
	 * Has to be called in the same function calling enter() for every return path.
	 *
	 * @param array $evaluateContext The current evaluation context
	 * @return void
	 */
	public function leave(array $evaluateContext) {
		if ($evaluateContext['currentPathIsEntryPoint']) {
			$this->inCacheEntryPoint = NULL;
		}
	}

	/**
	 * Builds an array of additional key / values which must go into the calculation of the cache entry identifier for
	 * a cached content segment.
	 *
	 * @param array $configuration
	 * @param string $typoScriptPath
	 * @param object $tsObject The actual TypoScript object
	 * @return array
	 */
	protected function buildCacheIdentifierValues($configuration, $typoScriptPath, $tsObject) {
		$cacheIdentifierValues = array();
		if (isset($configuration['entryIdentifier'])) {
			if (isset($configuration['entryIdentifier']['__objectType'])) {
				$cacheIdentifierValues = $this->runtime->evaluate($typoScriptPath . '/__meta/cache/entryIdentifier', $tsObject);
			} else {
				$cacheIdentifierValues = $this->runtime->evaluate($typoScriptPath . '/__meta/cache/entryIdentifier<TYPO3.TypoScript:GlobalCacheIdentifiers>', $tsObject);
			}
		} else {
			foreach ($this->runtime->getCurrentContext() as $key => $value) {
				if (is_string($value) || is_bool($value) || is_integer($value) || $value instanceof CacheAwareInterface) {
					$cacheIdentifierValues[$key] = $value;
				}
			}
		}
		return $cacheIdentifierValues;
	}

	/**
	 * Builds an array of string which must be used as tags for the cache entry identifier of a specific cached content segment.
	 *
	 * @param array $configuration
	 * @param string $typoScriptPath
	 * @param object $tsObject The actual TypoScript object
	 * @return array
	 */
	protected function buildCacheTags($configuration, $typoScriptPath, $tsObject) {
		$cacheTags = array();
		if (isset($configuration['entryTags'])) {
			foreach ($configuration['entryTags'] as $tagKey => $tagValue) {
				$tagValue = $this->runtime->evaluate($typoScriptPath . '/__meta/cache/entryTags/' . $tagKey, $tsObject);
				if (is_array($tagValue)) {
					$cacheTags = array_merge($cacheTags, $tagValue);
					continue;
				} 

				if ((string)$tagValue !== '') {
					$cacheTags[] = $tagValue;
				}
			}
		} else {
			$cacheTags = array(ContentCache::TAG_EVERYTHING);
		}
		return $cacheTags;
	}

	/**
	 * Generates an array of simple types from the given array of context variables
	 *
	 * @param array $contextVariables
	 * @return array
	 * @throws \InvalidArgumentException
	 */
	protected function serializeContext(array $contextVariables) {
		$serializedContextArray = array();
		foreach ($contextVariables as $variableName => $contextValue) {
			// TODO This relies on a converter being available from the context value type to string
			if ($contextValue !== NULL) {
				$serializedContextArray[$variableName]['type'] = \TYPO3\Flow\Utility\TypeHandling::getTypeForValue($contextValue);
				$serializedContextArray[$variableName]['value'] = $this->propertyMapper->convert($contextValue, 'string');
			}
		}

		return $serializedContextArray;
	}

	/**
	 * Unserialize a context that was previously serialized.
	 * INTERNAL use in a callback only.
	 *
	 * @param $serializedContextArray
	 * @return array
	 */
	public function unserializeContext($serializedContextArray) {
		$unserializedContext = array();
		foreach ($serializedContextArray as $variableName => $typeAndValue) {
			$value = $this->propertyMapper->convert($typeAndValue['value'], $typeAndValue['type']);
			$unserializedContext[$variableName] = $value;
		}

		return $unserializedContext;
	}

	/**
	 * Evaluate the "static" command
	 *
	 * @param string $cacheIdentifier For a static entry this is the entry identifier. As all commands are called in the same way we still need to give it.
	 * @param array $metadata
	 * @return mixed
	 */
	public function commandStatic($cacheIdentifier, array $metadata) {
		$result = $this->evaluate($metadata['path'], $metadata['context']);
		return $result;
	}

	/**
	 * Evaluate the "eval" command
	 *
	 * This is used to render uncached segments "out of band" in getCachedSegment of ContentCache.
	 *
	 * @param string $path The TypoScript path
	 * @param array $metadata
	 * @return mixed
	 */
	public function commandEval($path, array $metadata) {
		$result = $this->evaluate($path, $metadata['context'], FALSE);
		return $result;
	}

	/**
	 * Evaluate a TypoScript path with a given context and with or without caching
	 *
	 * @param string $path
	 * @param array $serializedContextArray Array of context properties cast to string. Each entry consists of an array with "type" and "value".
	 * @param boolean $cached Should the evaluation be done with enabled or disabled cache
	 * @return mixed
	 *
	 * TODO Find another way of disabling the cache (especially to allow cached content inside uncached content)
	 */
	protected function evaluate($path, $serializedContextArray, $cached = TRUE) {
		$contextArray = $this->unserializeContext($serializedContextArray);
		$previousEnableContentCache = $this->enableContentCache;
		$this->enableContentCache = $cached;
		$this->runtime->pushContextArray($contextArray);
		$result = $this->runtime->evaluate($path);
		$this->runtime->popContext();
		$this->enableContentCache = $previousEnableContentCache;
		return $result;
	}

	/**
	 * @param boolean $enableContentCache
	 * @return void
	 */
	public function setEnableContentCache($enableContentCache) {
		$this->enableContentCache = $enableContentCache;
	}

	/**
	 * @return boolean
	 */
	public function getEnableContentCache() {
		return $this->enableContentCache;
	}

}
