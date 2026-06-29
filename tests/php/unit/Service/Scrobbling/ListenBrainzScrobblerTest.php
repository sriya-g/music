<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Matthew Wells 2025
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\Service\Scrobbling;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;

class ListenBrainzScrobblerTest extends \PHPUnit\Framework\TestCase {
	private $config;
	private $logger;
	private $urlGenerator;
	private $albumBusinessLayer;
	private $crypto;
	private $scrobbler;

	protected function setUp() : void {
		$this->config = $this->getMockBuilder(IConfig::class)->getMock();
		$this->logger = $this->getMockBuilder(Logger::class)->disableOriginalConstructor()->getMock();
		$this->urlGenerator = $this->getMockBuilder(IURLGenerator::class)->getMock();
		$this->albumBusinessLayer = $this->getMockBuilder(AlbumBusinessLayer::class)->disableOriginalConstructor()->getMock();
		$this->crypto = $this->getMockBuilder(ICrypto::class)->getMock();

		$this->scrobbler = new ListenBrainzScrobbler(
			$this->config,
			$this->logger,
			$this->urlGenerator,
			$this->albumBusinessLayer,
			$this->crypto,
			'music'
		);
	}

	public function testGetName() {
		$this->assertEquals('ListenBrainz', $this->scrobbler->getName());
	}

	public function testGetIdentifier() {
		$this->assertEquals('listenbrainz', $this->scrobbler->getIdentifier());
	}

	public function testGetTokenRequestUrl() {
		$this->assertEmpty($this->scrobbler->getTokenRequestUrl());
	}
}
