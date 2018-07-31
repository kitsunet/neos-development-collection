<?php
namespace Neos\ContentRepository\DimensionSpace\DimensionSpace;

/*
 * This file is part of the Neos.ContentRepository.DimensionSpace package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\DimensionSpace\Dimension;

/**
 * The inter dimensional variation graph domain model
 * Represents the specialization and generalization mechanism between dimension space points
 */
class InterDimensionalVariationGraph
{
    /**
     * @var ContentDimensionZookeeper
     */
    protected $contentDimensionZookeeper;

    /**
     * @var Dimension\ContentDimensionSourceInterface
     */
    protected $contentDimensionSource;

    /**
     * @var array|WeightedDimensionSpacePoint[]
     */
    protected $weightedDimensionSpacePoints;

    /**
     * @var array|DimensionSpacePoint[][]
     */
    protected $indexedGeneralizations;

    /**
     * @var array|DimensionSpacePoint[][]
     */
    protected $indexedSpecializations;

    /**
     * @var array|DimensionSpacePoint[][]
     */
    protected $weightedGeneralizations;

    /**
     * @var array|DimensionSpacePoint[][][]
     */
    protected $weightedSpecializations;

    /**
     * @var array|DimensionSpacePoint[]
     */
    protected $primaryGeneralizations;

    /**
     * @var array|DimensionSpacePoint[]
     */
    protected $rootGeneralizations;

    /**
     * @var int
     */
    protected $weightNormalizationBase;

    public function __construct(Dimension\ContentDimensionSourceInterface $contentDimensionSource, ContentDimensionZookeeper $contentDimensionZookeeper)
    {
        $this->contentDimensionSource = $contentDimensionSource;
        $this->contentDimensionZookeeper = $contentDimensionZookeeper;
    }

    protected function initializeWeightedDimensionSpacePoints(): void
    {
        $this->weightedDimensionSpacePoints = [];
        foreach ($this->contentDimensionZookeeper->getAllowedCombinations() as $dimensionValues) {
            $weightedDimensionSpacePoint = new WeightedDimensionSpacePoint($dimensionValues);
            $this->weightedDimensionSpacePoints[$weightedDimensionSpacePoint->getIdentityHash()] = $weightedDimensionSpacePoint;
        }
    }

    /**
     * @return array|WeightedDimensionSpacePoint[]
     * @api
     */
    public function getWeightedDimensionSpacePoints(): array
    {
        if (is_null($this->weightedDimensionSpacePoints)) {
            $this->initializeWeightedDimensionSpacePoints();
        }

        return $this->weightedDimensionSpacePoints;
    }

    /**
     * @param DimensionSpacePoint $point
     * @return WeightedDimensionSpacePoint|null
     */
    public function getWeightedDimensionSpacePointByDimensionSpacePoint(DimensionSpacePoint $point): ?WeightedDimensionSpacePoint
    {
        return $this->getWeightedDimensionSpacePointByHash($point->getHash());
    }

    /**
     * @param string $hash
     * @return WeightedDimensionSpacePoint|null
     */
    public function getWeightedDimensionSpacePointByHash(string $hash): ?WeightedDimensionSpacePoint
    {
        if (is_null($this->weightedDimensionSpacePoints)) {
            $this->initializeWeightedDimensionSpacePoints();
        }

        return isset($this->weightedDimensionSpacePoints[$hash]) ? $this->weightedDimensionSpacePoints[$hash] : null;
    }

    /**
     * @return array|DimensionSpacePoint[]
     */
    public function getRootGeneralizations(): array
    {
        $rootGeneralizations = [];
        foreach ($this->getWeightedDimensionSpacePoints() as $dimensionSpacePointHash => $weightedDimensionSpacePoint) {
            if (empty($this->getIndexedGeneralizations($weightedDimensionSpacePoint->getDimensionSpacePoint()))) {
                $rootGeneralizations[$dimensionSpacePointHash] = $weightedDimensionSpacePoint->getDimensionSpacePoint();
            }
        }

        return $rootGeneralizations;
    }

    /**
     * @return int
     */
    protected function determineWeightNormalizationBase(): int
    {
        if (is_null($this->weightNormalizationBase)) {
            $base = 0;
            foreach ($this->contentDimensionSource->getContentDimensionsOrderedByPriority() as $contentDimension) {
                $base = max($base, $contentDimension->getMaximumDepth()->getDepth() + 1);
            }

            $this->weightNormalizationBase = $base;
        }

        return $this->weightNormalizationBase;
    }

    /**
     * @return void
     */
    protected function initializeVariations()
    {
        $normalizedVariationWeights = [];
        $lowestVariationWeights = [];

        foreach ($this->getWeightedDimensionSpacePoints() as $generalizationHash => $generalization) {
            if (!isset($normalizedVariationWeights[$generalizationHash])) {
                $normalizedVariationWeights[$generalizationHash] = $generalization->getWeight()->normalize($this->determineWeightNormalizationBase());
            }

            foreach ($generalization->getDimensionValues() as $rawDimensionIdentifier => $contentDimensionValue) {
                $dimensionIdentifier = new Dimension\ContentDimensionIdentifier($rawDimensionIdentifier);
                $dimension = $this->contentDimensionSource->getDimension($dimensionIdentifier);
                foreach ($dimension->getSpecializations($contentDimensionValue) as $specializedValue) {
                    $specializedDimensionSpacePoint = $generalization->getDimensionSpacePoint()->vary($dimensionIdentifier, (string) $specializedValue);
                    if (!$this->contentDimensionZookeeper->getAllowedDimensionSubspace()->contains($specializedDimensionSpacePoint)) {
                        continue;
                    }
                    $specialization = $this->getWeightedDimensionSpacePointByDimensionSpacePoint($specializedDimensionSpacePoint);

                    if (!isset($normalizedVariationWeights[$specialization->getIdentityHash()])) {
                        $normalizedVariationWeights[$specialization->getIdentityHash()] = $specialization->getWeight()->normalize($this->determineWeightNormalizationBase());
                    }
                    $this->initializeVariationsForDimensionSpacePointPair($specialization, $generalization, $normalizedVariationWeights);
                    $normalizedVariationWeight = $normalizedVariationWeights[$specialization->getIdentityHash()] - $normalizedVariationWeights[$generalizationHash];
                    if (!isset($lowestVariationWeights[$specialization->getIdentityHash()]) || $normalizedVariationWeight < $lowestVariationWeights[$specialization->getIdentityHash()]) {
                        $this->primaryGeneralizations[$specialization->getIdentityHash()] = $generalization->getDimensionSpacePoint();
                    }
                }
            }
        }

        foreach ($this->weightedGeneralizations as $specializationHash => &$generalizationsByWeight) {
            ksort($generalizationsByWeight);
        }
    }

    /**
     * @param WeightedDimensionSpacePoint $specialization
     * @param WeightedDimensionSpacePoint $generalization
     * @param array $normalizedVariationWeights
     */
    protected function initializeVariationsForDimensionSpacePointPair(WeightedDimensionSpacePoint $specialization, WeightedDimensionSpacePoint $generalization, array $normalizedVariationWeights)
    {
        /** @var array|WeightedDimensionSpacePoint[] $generalizationsToProcess */
        $generalizationsToProcess = [$generalization->getIdentityHash() => $generalization];
        if (isset($this->indexedGeneralizations[$generalization->getIdentityHash()])) {
            foreach ($this->indexedGeneralizations[$generalization->getIdentityHash()] as $parentGeneralizationHash => $parentGeneralization) {
                $generalizationsToProcess[$parentGeneralizationHash] = $this->getWeightedDimensionSpacePointByHash($parentGeneralizationHash);
            }
        }

        foreach ($generalizationsToProcess as $generalizationHashToProcess => $generalizationToProcess) {
            $normalizedWeightDifference = abs($normalizedVariationWeights[$generalizationHashToProcess] - $normalizedVariationWeights[$specialization->getIdentityHash()]);
            $this->indexedGeneralizations[$specialization->getIdentityHash()][$generalizationToProcess->getIdentityHash()] = $generalizationToProcess->getDimensionSpacePoint();
            $this->weightedGeneralizations[$specialization->getIdentityHash()][$normalizedWeightDifference] = $generalizationToProcess->getDimensionSpacePoint();

            $this->indexedSpecializations[$generalizationToProcess->getIdentityHash()][$specialization->getIdentityHash()] = $specialization->getDimensionSpacePoint();
            $this->weightedSpecializations[$generalizationToProcess->getIdentityHash()][$normalizedWeightDifference][$specialization->getIdentityHash()] = $specialization->getDimensionSpacePoint();
        }
    }

    /**
     * Returns specializations of a subgraph indexed by hash
     *
     * @param DimensionSpacePoint $generalization
     * @return array|DimensionSpacePoint[]
     */
    public function getIndexedSpecializations(DimensionSpacePoint $generalization): array
    {
        if (is_null($this->indexedSpecializations)) {
            $this->initializeVariations();
        }

        return $this->indexedSpecializations[$generalization->getHash()] ?? [];
    }

    /**
     * Returns generalizations of a subgraph indexed by hash
     *
     * @param DimensionSpacePoint $specialization
     * @return array|DimensionSpacePoint[]
     */
    public function getIndexedGeneralizations(DimensionSpacePoint $specialization): array
    {
        if (is_null($this->indexedGeneralizations)) {
            $this->initializeVariations();
        }

        return $this->indexedGeneralizations[$specialization->getHash()] ?? [];
    }

    /**
     * Returns specializations of a subgraph indexed by relative weight
     *
     * @param DimensionSpacePoint $generalization
     * @return array|DimensionSpacePoint[][]
     */
    public function getWeightedSpecializations(DimensionSpacePoint $generalization): array
    {
        if (is_null($this->weightedSpecializations)) {
            $this->initializeVariations();
        }

        return $this->weightedSpecializations[$generalization->getHash()] ?? [];
    }

    /**
     * Returns generalizations of a subgraph indexed by relative weight
     *
     * @param DimensionSpacePoint $specialization
     * @return array|DimensionSpacePoint[]
     */
    public function getWeightedGeneralizations(DimensionSpacePoint $specialization): array
    {
        if (is_null($this->weightedGeneralizations)) {
            $this->initializeVariations();
        }

        return $this->weightedGeneralizations[$specialization->getHash()] ?? [];
    }

    /**
     * @param DimensionSpacePoint $origin
     * @param bool $includeOrigin
     * @param DimensionSpacePointSet|null $excludedSet
     * @return DimensionSpacePointSet
     * @throws Exception\DimensionSpacePointNotFound
     */
    public function getSpecializationSet(
        DimensionSpacePoint $origin,
        bool $includeOrigin = true,
        DimensionSpacePointSet $excludedSet = null
    ): DimensionSpacePointSet {
        if (!$this->contentDimensionZookeeper->getAllowedDimensionSubspace()->contains($origin)) {
            throw new Exception\DimensionSpacePointNotFound(sprintf('%s was not found in the allowed dimension subspace', $origin), 1505929456);
        } else {
            $specializations = [];
            if ($includeOrigin) {
                $specializations[$origin->getHash()] = $origin;
            }

            foreach ($this->getIndexedSpecializations($origin) as $specialization) {
                if (!$excludedSet || !$excludedSet->contains($specialization)) {
                    $specializations[$specialization->getHash()] = $specialization;
                }
            }

            return new DimensionSpacePointSet($specializations);
        }
    }

    /**
     * @param DimensionSpacePoint $specialization
     * @return DimensionSpacePoint|null
     * @api
     */
    public function getPrimaryGeneralization(DimensionSpacePoint $specialization): ?DimensionSpacePoint
    {
        if (is_null($this->indexedGeneralizations)) {
            $this->initializeVariations();
        }

        return $this->primaryGeneralizations[$specialization->getHash()] ?? null;
    }
}
