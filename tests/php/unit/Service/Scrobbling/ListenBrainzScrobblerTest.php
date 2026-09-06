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
use OCA\Music\BusinessLayer\ArtistBusinessLayer;
use OCA\Music\Db\Album;
use OCA\Music\Db\Artist;
use OCA\Music\Db\Track;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;

class ListenBrainzScrobblerTest extends \PHPUnit\Framework\TestCase {
	private $config;
	private $logger;
	private $urlGenerator;
	private $albumBusinessLayer;
	private $artistBusinessLayer;
	private $crypto;
	private $scrobbler;

	protected function setUp() : void {
		$this->config = $this->getMockBuilder(IConfig::class)->getMock();
		$this->logger = $this->getMockBuilder(Logger::class)->disableOriginalConstructor()->getMock();
		$this->urlGenerator = $this->getMockBuilder(IURLGenerator::class)->getMock();
		$this->albumBusinessLayer = $this->getMockBuilder(AlbumBusinessLayer::class)->disableOriginalConstructor()->getMock();
		$this->artistBusinessLayer = $this->getMockBuilder(ArtistBusinessLayer::class)->disableOriginalConstructor()->getMock();
		$this->crypto = $this->getMockBuilder(ICrypto::class)->getMock();

		$this->scrobbler = new ListenBrainzScrobbler(
			$this->config,
			$this->logger,
			$this->urlGenerator,
			$this->albumBusinessLayer,
			$this->artistBusinessLayer,
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

	public function testBuildAdditionalInfoWithMbids() {
		$track = new Track();
		$track->setTitle('Test Song');
		$track->setNumber(5);
		$track->setLength(210);
		$track->setMbid('11111111-1111-1111-1111-111111111111');
		$track->setMbidRelTrack('22222222-2222-2222-2222-222222222222');
		$track->setArtistId(42);

		$album = new Album();
		$album->setName('Test Album');
		$album->setMbid('33333333-3333-3333-3333-333333333333');
		$album->setMbidGroup('44444444-4444-4444-4444-444444444444');
		$album->setAlbumArtistName('Test Album Artist');
		$track->setAlbum($album);

		$artist = new Artist();
		$artist->setId(42);
		$artist->setName('Test Artist');
		$artist->setMbid('55555555-5555-5555-5555-555555555555');

		$this->artistBusinessLayer->expects($this->once())
			->method('find')
			->with(42, 'testuser')
			->willReturn($artist);

		$reflection = new \ReflectionClass(ListenBrainzScrobbler::class);
		$method = $reflection->getMethod('buildAdditionalInfo');
		$method->setAccessible(true);

		$result = $method->invoke($this->scrobbler, $track, 'testuser');

		$this->assertEquals(5, $result['tracknumber']);
		$this->assertEquals(210, $result['duration']);
		$this->assertEquals('Test Album Artist', $result['albumartist']);
		$this->assertEquals('11111111-1111-1111-1111-111111111111', $result['recording_mbid']);
		$this->assertEquals('22222222-2222-2222-2222-222222222222', $result['track_mbid']);
		$this->assertEquals('33333333-3333-3333-3333-333333333333', $result['release_mbid']);
		$this->assertEquals('44444444-4444-4444-4444-444444444444', $result['release_group_mbid']);
		$this->assertEquals(['55555555-5555-5555-5555-555555555555'], $result['artist_mbids']);
	}

	public function testBuildAdditionalInfoWithoutMbids() {
		$track = new Track();
		$track->setTitle('Plain Song');
		$track->setLength(180);

		$reflection = new \ReflectionClass(ListenBrainzScrobbler::class);
		$method = $reflection->getMethod('buildAdditionalInfo');
		$method->setAccessible(true);

		$result = $method->invoke($this->scrobbler, $track, 'testuser');

		$this->assertEquals(180, $result['duration']);
		$this->assertArrayNotHasKey('recording_mbid', $result);
		$this->assertArrayNotHasKey('track_mbid', $result);
		$this->assertArrayNotHasKey('release_mbid', $result);
		$this->assertArrayNotHasKey('release_group_mbid', $result);
		$this->assertArrayNotHasKey('artist_mbids', $result);
	}

	public function testLoveTrackWithoutSession() {
		$track = new Track();
		$track->setTitle('Song');
		$track->setArtistName('Artist');
		$track->setUserId('testuser');

		$this->config->expects($this->once())
			->method('getUserValue')
			->with('testuser', 'music', 'listenbrainz.scrobbleSessionKey')
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
			->with('testuser', 'music', 'listenbrainz.scrobbleSessionKey')
			->willReturn(null);

		$this->scrobbler->unloveTrack($track);
		$this->assertTrue(true);
	}
}
