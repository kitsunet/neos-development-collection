<?php
namespace TYPO3\Neos\TypeConverter;

/*
 * This file is part of the TYPO3.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Property\PropertyMappingConfigurationInterface;

/**
 * An Object Converter for nodes which can be used for routing (but also for other
 * purposes) as a plugin for the Property Mapper.
 *
 * @Flow\Scope("singleton")
 */
class NodeConverter extends \TYPO3\TYPO3CR\TypeConverter\NodeConverter
{
    /**
     * @Flow\Inject
     * @var \TYPO3\Neos\Domain\Repository\DomainRepository
     */
    protected $domainRepository;

    /**
     * @Flow\Inject
     * @var \TYPO3\Neos\Domain\Repository\SiteRepository
     */
    protected $siteRepository;

    /**
     * @var integer
     */
    protected $priority = 3;

    /**
     * Additionally add the current site and domain to the Context properties.
     *
     * {@inheritdoc}
     */
    protected function prepareContextProperties($nodePath, $workspaceName, PropertyMappingConfigurationInterface $configuration = null, array $dimensions = null)
    {
        $contextProperties = parent::prepareContextProperties($nodePath, $workspaceName, $configuration, $dimensions);
        $site = $this->siteRepository->findOneByNodePath($nodePath);
        $contextProperties['currentSite'] = $site;
        $contextProperties['currentDomain'] = $site->getFirstActiveDomain();

        return $contextProperties;
    }
}
