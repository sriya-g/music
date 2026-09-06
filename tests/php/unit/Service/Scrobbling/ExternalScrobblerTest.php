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

namespace OCA\Music\Service\Scrobbling;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\Db\Track;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;

class ExternalScrobblerTest extends \PHPUnit\Framework\TestCase {
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

		$this->scrobbler = new ExternalScrobbler(
			$this->config,
			$this->logger,
			$this->urlGenerator,
			$this->albumBusinessLayer,
			$this->crypto,
			'Last.fm',
			'lastfm',
			'http://ws.audioscrobbler.com/2.0/',
			'http://www.last.fm/api/auth/',
			'music'
		);
	}

	public function testGetName() {
		$this->assertEquals('Last.fm', $this->scrobbler->getName());
	}

	public function testGetIdentifier() {
		$this->assertEquals('lastfm', $this->scrobbler->getIdentifier());
	}

	public function testLoveTrackWithoutSession() {
		$track = new Track();
		$track->setTitle('Song');
		$track->setArtistName('Artist');
		$track->setUserId('testuser');

		$this->config->expects($this->once())
			->method('getUserValue')
			->with('testuser', 'music', 'lastfm.scrobbleSessionKey')
			->willReturn(null);

		$this->scrobbler->loveTrack($track);
		$this->assertTrue(true);
	}

	public function testUnloveTrackWithoutSession() {
		$track = new Track();
		$track->setTitle('Song');
		$track->setArtistName('Artist');
		$track->setUserId('testuser');

		$this->config->expects($this->once())
			->method('getUserValue')
			->with('testuser', 'music', 'lastfm.scrobbleSessionKey')
			->willReturn(null);

		$this->scrobbler->unloveTrack($track);
		$this->assertTrue(true);
	}

	public function testLoveTrackWithEmptyArtistOrTitle() {
		$track = new Track();
		$track->setTitle('');
		$track->setArtistName('');
		$track->setUserId('testuser');

		$this->config->expects($this->once())
			->method('getUserValue')
			->with('testuser', 'music', 'lastfm.scrobbleSessionKey')
			->willReturn('encrypted-dummy-key');
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('secret')
			->willReturn('secret');
		$this->crypto->expects($this->once())
			->method('decrypt')
			->willReturn('valid-session-key');

		// Since title and artist are empty, loveTrack should skip without calling execRequest
		$this->scrobbler->loveTrack($track);
		$this->assertTrue(true);
	}
}
