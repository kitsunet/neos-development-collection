<?php
namespace Neos\Node\Routing\Node;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Utility\Arrays;
use TYPO3\TYPO3CR\Domain\Service\Context;
use TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface;

/**
 * @Flow\Scope("singleton")
 */
class RequestedNodeContextHolder
{
    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @var array
     */
    protected $contextProperties;

    /**
     * @var Context
     */
    protected $context;

    /**
     * RequestedNodeContextHolder constructor.
     *
     * @param array $contextProperties
     */
    public function __construct($contextProperties = [])
    {
        $this->contextProperties = $contextProperties;
    }

    /**
     * @return array
     */
    public function getContextProperties()
    {
        return $this->contextProperties;
    }

    /**
     * @param array $contextProperties
     */
    public function setContextProperties($contextProperties)
    {
        $this->contextProperties = $contextProperties;
    }

    /**
     * @param string $path
     * @param mixed $value
     */
    public function setPropertyByPath($path, $value)
    {
        $this->contextProperties = Arrays::setValueByPath($this->contextProperties, $path, $value);
    }

    /**
     * @return boolean
     */
    public function isFrozen()
    {
        return ($this->context !== null);
    }

    /**
     * @return Context
     */
    public function getContext()
    {
        $this->context = $this->contextFactory->create($this->contextProperties);

        return $this->context;
    }
}
