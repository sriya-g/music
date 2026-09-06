<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\Service;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\BusinessLayer\ArtistBusinessLayer;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCP\IConfig;

class LastfmServiceTest extends \PHPUnit\Framework\TestCase {
	private $config;
	private $albumBusinessLayer;
	private $artistBusinessLayer;
	private $trackBusinessLayer;
	private $logger;
	private LastfmService $service;

	protected function setUp() : void {
		$this->config = $this->getMockBuilder(IConfig::class)->getMock();
		$this->albumBusinessLayer = $this->getMockBuilder(AlbumBusinessLayer::class)->disableOriginalConstructor()->getMock();
		$this->artistBusinessLayer = $this->getMockBuilder(ArtistBusinessLayer::class)->disableOriginalConstructor()->getMock();
		$this->trackBusinessLayer = $this->getMockBuilder(TrackBusinessLayer::class)->disableOriginalConstructor()->getMock();
		$this->logger = $this->getMockBuilder(Logger::class)->disableOriginalConstructor()->getMock();

		$this->config->expects($this->any())
			->method('getSystemValue')
			->with('music.lastfm_api_key', '')
			->will($this->returnValue('test_api_key'));

		$this->service = new LastfmService(
			$this->config,
			$this->albumBusinessLayer,
			$this->artistBusinessLayer,
			$this->trackBusinessLayer,
			$this->logger
		);
	}

	public function testNormalizeString() {
		$this->assertEquals('comfortably numb', LastfmService::normalizeString('  Comfortably   Numb  '));
		$this->assertEquals('hotel california', LastfmService::normalizeString('Hotel California'));
	}

	public function testNormalizeStringLoose() {
		$this->assertEquals('comfortably numb', LastfmService::normalizeStringLoose('Comfortably Numb (2011 Remastered Version)'));
		$this->assertEquals('song title', LastfmService::normalizeStringLoose('Song Title [Live at Wembley]'));
		$this->assertEquals('track feat guest', LastfmService::normalizeStringLoose('Track (feat. Guest Artist)'));
		$this->assertEquals('dont stop believin', LastfmService::normalizeStringLoose("Don't Stop Believin'"));
	}
}
