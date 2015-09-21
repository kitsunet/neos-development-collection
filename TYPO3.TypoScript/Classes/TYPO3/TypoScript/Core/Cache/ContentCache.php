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
use TYPO3\Flow\Security\Context;
use TYPO3\TypoScript\Exception;
use Doctrine\ORM\Proxy\Proxy;

/**
 * A wrapper around a TYPO3 Flow cache which provides additional functionality for caching partial content (segments)
 * rendered by the TypoScript Runtime.
 *
 * The cache build process generally follows these steps:
 *
 *  - render the whole document as usual (for example a page) but insert special markers before and after the rendered segments
 *  - parse the rendered document and extract segments by the previously added markers
 *
 * This results in two artifacts:
 *
 *  - an array of content segments which are later stored as cache entries (if they may be cached)
 *  - a string called "output" which is the originally rendered output but without the markers
 *
 * We use non-visible ASCII characters as markers / tokens in order to minimize potential conflicts with the actual content.
 *
 * Note: If you choose a different cache backend for this content cache, make sure that it is one implementing
 *       TaggableBackendInterface.
 *
 * @Flow\Scope("singleton")
 */
class ContentCache {

	const CACHE_SEGMENT_START_TOKEN = "\x02";
	const CACHE_SEGMENT_END_TOKEN = "\x03";
	const CACHE_SEGMENT_SEPARATOR_TOKEN = "\x1f";

	const CACHE_PLACEHOLDER_REGEX = "/\x02(?P<commandName>[a-zA-z0-9]+)=(?P<commandArgument>[^\x02\x1f\x03]+)\x1f(?P<metadata>[^\x02\x1f\x03]+)\x03/";

	const CACHE_COMMAND_STATIC = 'static';
	const CACHE_COMMAND_UNCACHED = 'eval';

	const MAXIMUM_NESTING_LEVEL = 32;

	/**
	 * A cache entry tag that will be used by default to flush an entry on "every" change - whatever that means to
	 * the application.
	 */
	const TAG_EVERYTHING = 'Everything';

	/**
	 * @Flow\Inject
	 * @var CacheSegmentParser
	 */
	protected $parser;

	/**
	 * @var \TYPO3\Flow\Cache\Frontend\StringFrontend
	 * @Flow\Inject
	 */
	protected $cache;

	/**
	 * Takes the given content and adds markers for later use as a cached content segment.
	 *
	 * @see createSegment()
	 * Convenience method to avoid exposure of renderContentCacheEntryIdentifier()
	 *
	 * @param string $content The (partial) content which should potentially be cached later on
	 * @param array $cacheIdentifierValues The values (simple type or implementing CacheAwareInterface) that should be used to create a cache identifier, will be sorted by keys for consistent ordering
	 * @param array $metadata Metadata for the cached entry. Contains: "tags", "lifetime", "context", "path"
	 * @return string The original content, but with additional markers and a cache identifier added
	 */
	public function createCacheSegment($content, $cacheIdentifierValues, array $metadata = array()) {
		$cacheIdentifier = $this->renderContentCacheEntryIdentifier($metadata['path'], $cacheIdentifierValues);
		return $this->createSegment($content, self::CACHE_COMMAND_STATIC, $cacheIdentifier, $metadata);
	}

	/**
	 * Takes the given content and adds markers for later use as a cache segment.
	 *
	 * This function will add a start and an end token to the beginning and end of the content and add the given
	 * command, commandArgument and metadata.
	 *
	 * The whole cache segment (START TOKEN + COMMAND NAME = COMMAND VALUE + SEPARATOR TOKEN + JSON METADATA + SEPARATOR TOKEN + original content + END TOKEN) is returned
	 * as a string.
	 *
	 * This method is called by the TypoScript RuntimeContentCache while rendering a TypoScript object.
	 *
	 * @param string $content
	 * @param string $commandName One of the ContentCache::CACHE_COMMAND_* constants
	 * @param string $commandArgument The argument for the command. Eg. CACHE_COMMAND_UNCACHED expects the cache identifier
	 * @param array $metadata Array of metadata for the segment. Usually contains the context, maybe cache tags, lifetime and path.
	 * @return string
	 */
	public function createSegment($content, $commandName, $commandArgument, array $metadata = array()) {
		return self::CACHE_SEGMENT_START_TOKEN . $commandName . '=' . $commandArgument . self::CACHE_SEGMENT_SEPARATOR_TOKEN . json_encode($metadata) . self::CACHE_SEGMENT_SEPARATOR_TOKEN . $content . self::CACHE_SEGMENT_END_TOKEN;
	}

	/**
	 * Renders an identifier for a content cache entry
	 *
	 * @param string $typoScriptPath
	 * @param array $cacheIdentifierValues
	 * @return string An MD5 hash built from the typoScriptPath and certain elements of the given identifier values
	 * @throws \TYPO3\TypoScript\Exception\CacheException If an invalid entry identifier value is given
	 */
	protected function renderContentCacheEntryIdentifier($typoScriptPath, array $cacheIdentifierValues) {
		ksort($cacheIdentifierValues);

		$identifierSource = '';
		foreach ($cacheIdentifierValues as $key => $value) {
			if ($value instanceof CacheAwareInterface) {
				$identifierSource .= $key . '=' . $value->getCacheEntryIdentifier() . '&';
			} elseif (is_string($value) || is_bool($value) || is_integer($value)) {
				$identifierSource .= $key . '=' . $value . '&';
			} elseif ($value !== NULL) {
				throw new Exception\CacheException(sprintf('Invalid cache entry identifier @cache.entryIdentifier.%s for path "%s". A entry identifier value must be a string or implement CacheAwareInterface.', $key, $typoScriptPath), 1395846615);
			}
		}
		$identifierSource .= 'securityContextHash=' . $this->securityContext->getContextHash();

		return md5($typoScriptPath . '@' . rtrim($identifierSource, '&'));
	}

	/**
	 * Takes a string of content which includes cache segment markers, extracts the marked segments, writes those
	 * segments which can be cached to the actual cache and returns the cleaned up original content without markers.
	 *
	 * This method is called by the TypoScript RuntimeContentCache while rendering a TypoScript object.
	 *
	 * @param string $content The content with an outer cache segment
	 * @param boolean $storeCacheEntries Whether to store extracted cache segments in the cache
	 * @return string The (pure) content without cache segment markers
	 */
	public function processCacheSegments($content, $storeCacheEntries = TRUE) {
		$this->parser->extractRenderedSegments($content);

		if ($storeCacheEntries) {
			$segments = $this->parser->getCacheSegments();

			foreach ($segments as $segment) {
				$metadata = json_decode($segment['metadata'], TRUE);
					// FALSE means we do not need to store the cache entry again (because it was previously fetched)
				if (is_array($metadata)) {
					$this->cache->set($segment['commandArgument'], $segment['content'], $this->sanitizeTags($metadata['tags']), $metadata['lifetime']);
				}
			}
		}

		return $this->parser->getOutput();
	}

	/**
	 * Tries to retrieve the specified content segment from the cache – further nested inline segments are retrieved
	 * as well and segments which were not cacheable are rendered.
	 *
	 * @param \Closure $commandCallback A callback to process commands in uncached segments
	 * @param string $typoScriptPath TypoScript path identifying the TypoScript object to retrieve from the content cache
	 * @param array $cacheIdentifierValues Further values which play into the cache identifier hash, must be the same as the ones specified while the cache entry was written
	 * @param boolean $addCacheSegmentMarkersToPlaceholders If cache segment markers should be added – this makes sense if the cached segment is about to be included in a not-yet-cached segment
	 * @return string|boolean The segment with replaced cache placeholders, or FALSE if a segment was missing in the cache
	 * @throws \TYPO3\TypoScript\Exception
	 */
	public function getCachedSegment($commandCallback, $typoScriptPath, $cacheIdentifierValues, $addCacheSegmentMarkersToPlaceholders = FALSE) {
		$cacheIdentifier = $this->renderContentCacheEntryIdentifier($typoScriptPath, $cacheIdentifierValues);
		$content = $this->cache->get($cacheIdentifier);

		if ($content === FALSE) {
			return FALSE;
		}

		$content = $this->replaceCachePlaceholders($commandCallback, $content, $addCacheSegmentMarkersToPlaceholders);

		if ($addCacheSegmentMarkersToPlaceholders) {
			return self::CACHE_SEGMENT_START_TOKEN . self::CACHE_COMMAND_STATIC . '=' . $cacheIdentifier . self::CACHE_SEGMENT_SEPARATOR_TOKEN . '*' . self::CACHE_SEGMENT_SEPARATOR_TOKEN . $content . self::CACHE_SEGMENT_END_TOKEN;
		} else {
			return $content;
		}
	}

	/**
	 * Find cache placeholders in a cached segment and return the identifiers
	 *
	 * @param \Closure $commandCallback
	 * @param string $content
	 * @param boolean $addCacheSegmentMarkersToPlaceholders
	 * @param integer $recursionLevel
	 * @return integer|boolean Number of replaced placeholders or FALSE if a placeholder couldn't be found
	 */
	public function replaceCachePlaceholders($commandCallback, $content, $addCacheSegmentMarkersToPlaceholders, $recursionLevel = 0) {
		$cache = $this->cache;
		$self = $this;
		$content = preg_replace_callback(self::CACHE_PLACEHOLDER_REGEX, function($match) use ($commandCallback, $cache, $addCacheSegmentMarkersToPlaceholders, $recursionLevel, $self) {
			$entry = FALSE;
			$commandName = $match['commandName'];
			$commandArgument = $match['commandArgument'];
			if ($commandName === ContentCache::CACHE_COMMAND_STATIC) {
				$entry = $cache->get($commandArgument);
			}

			if ($entry === FALSE || $entry === NULL) {
				$contextString = $match['metadata'];
				$metadataArray = json_decode($contextString, TRUE);

				$entry = $commandCallback($commandName, $commandArgument, $metadataArray);
				$entry = $self->processCacheSegments($entry);
			} else {
				if ($recursionLevel > self::MAXIMUM_NESTING_LEVEL) {
					throw new Exception('Maximum cache segment level reached', 1391873620);
				}
				$recursionLevel++;
				$entry = $self->replaceCachePlaceholders($commandCallback, $entry, $addCacheSegmentMarkersToPlaceholders, $recursionLevel);
			}

			if ($addCacheSegmentMarkersToPlaceholders) {
				return ContentCache::CACHE_SEGMENT_START_TOKEN . $commandName . '=' . $commandArgument . ContentCache::CACHE_SEGMENT_SEPARATOR_TOKEN . '*' . ContentCache::CACHE_SEGMENT_SEPARATOR_TOKEN . $entry . ContentCache::CACHE_SEGMENT_END_TOKEN;
			} else {
				return $entry;
			}
		}, $content, -1);

		return $content;
	}

	/**
	 * Flush content cache entries by tag
	 *
	 * @param string $tag A tag value that was assigned to a cache entry in TypoScript, for example "Everything", "Node_[…]", "NodeType_[…]", "DescendantOf_[…]" whereas "…" is the node identifier or node type respectively
	 * @return integer The number of cache entries which actually have been flushed
	 */
	public function flushByTag($tag) {
		return $this->cache->flushByTag($this->sanitizeTag($tag));
	}

	/**
	 * Flush all content cache entries
	 *
	 * @return void
	 */
	public function flush() {
		$this->cache->flush();
	}

	/**
	 * Sanitizes the given tag for use with the cache framework
	 *
	 * @param string $tag A tag which possibly contains non-allowed characters, for example "NodeType_TYPO3.Neos.NodeTypes:Page"
	 * @return string A cleaned up tag, for example "NodeType_TYPO3_Neos-Page"
	 */
	protected function sanitizeTag($tag) {
		return strtr($tag, '.:', '_-');
	}

	/**
	 * Sanitizes multiple tags with sanitizeTag()
	 *
	 * @param array $tags Multiple tags
	 * @return array The sanitized tags
	 */
	protected function sanitizeTags(array $tags) {
		foreach ($tags as $key => $value) {
			$tags[$key] = $this->sanitizeTag($value);
		}
		return $tags;
	}
}
