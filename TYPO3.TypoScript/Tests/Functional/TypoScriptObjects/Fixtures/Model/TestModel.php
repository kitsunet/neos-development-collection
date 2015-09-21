<?php
namespace TYPO3\TypoScript\Tests\Functional\TypoScriptObjects\Fixtures\Model;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "TypoScript".            *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use Doctrine\ORM\Mapping as ORM;

/**
 * A simple cache aware model
 *
 * @Flow\Entity
 */
class TestModel implements \TYPO3\Flow\Cache\CacheAwareInterface {

	/**
	 * @var string
	 * @ORM\Id
	 * @Flow\Identity
	 */
	protected $id;

	/**
	 * @var string
	 */
	protected $value;

	/**
	 * @var integer
	 */
	protected $counter = 0;

	public function __construct($id, $value) {
		$this->id = $id;
		$this->value = $value;
	}

	/**
	 * @param string $value
	 */
	public function setValue($value) {
		$this->value = $value;
	}

	/**
	 * @return string
	 */
	public function getValue() {
		return $this->value;
	}

	/**
	 * @return string
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * Increment and get counter
	 *
	 * @return integer
	 */
	public function getCounter() {
		$this->counter++;
		return $this->counter;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function getCacheEntryIdentifier() {
		return $this->id;
	}

}
