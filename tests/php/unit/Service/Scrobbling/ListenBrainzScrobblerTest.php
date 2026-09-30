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

	public function testIsValidMbid() {
		$this->assertTrue(ListenBrainzScrobbler::isValidMbid('11111111-1111-1111-1111-111111111111'));
		$this->assertTrue(ListenBrainzScrobbler::isValidMbid('c4c7c8c8-3e4b-4b16-848e-2895f36e4f3a'));
		$this->assertTrue(ListenBrainzScrobbler::isValidMbid('C4C7C8C8-3E4B-4B16-848E-2895F36E4F3A'));

		$this->assertFalse(ListenBrainzScrobbler::isValidMbid(null));
		$this->assertFalse(ListenBrainzScrobbler::isValidMbid(''));
		$this->assertFalse(ListenBrainzScrobbler::isValidMbid('   '));
		$this->assertFalse(ListenBrainzScrobbler::isValidMbid('00000000-0000-0000-0000-000000000000'));
		$this->assertFalse(ListenBrainzScrobbler::isValidMbid('invalid-mbid'));
		$this->assertFalse(ListenBrainzScrobbler::isValidMbid('11111111-1111-1111-1111-11111111111')); // too short
		$this->assertFalse(ListenBrainzScrobbler::isValidMbid('11111111-1111-1111-1111-1111111111111')); // too long
		$this->assertFalse(ListenBrainzScrobbler::isValidMbid('zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz')); // non-hex
	}

	public function testBuildAdditionalInfoWithInvalidMbids() {
		$track = new Track();
		$track->setTitle('Song With Invalid Mbids');
		$track->setLength(200);
		$track->setMbid('not-a-valid-uuid');
		$track->setMbidRelTrack('00000000-0000-0000-0000-000000000000');
		$track->setArtistId(99);

		$album = new Album();
		$album->setName('Album');
		$album->setMbid('bad-album-mbid');
		$album->setMbidGroup('');
		$track->setAlbum($album);

		$artist = new Artist();
		$artist->setId(99);
		$artist->setName('Artist');
		$artist->setMbid('bad-artist-mbid');

		$this->artistBusinessLayer->expects($this->once())
			->method('find')
			->with(99, 'testuser')
			->willReturn($artist);

		$reflection = new \ReflectionClass(ListenBrainzScrobbler::class);
		$method = $reflection->getMethod('buildAdditionalInfo');
		$method->setAccessible(true);

		$result = $method->invoke($this->scrobbler, $track, 'testuser');

		$this->assertEquals(200, $result['duration']);
		$this->assertArrayNotHasKey('recording_mbid', $result);
		$this->assertArrayNotHasKey('track_mbid', $result);
		$this->assertArrayNotHasKey('release_mbid', $result);
		$this->assertArrayNotHasKey('release_group_mbid', $result);
		$this->assertArrayNotHasKey('artist_mbids', $result);
	}

	public function testHasMbidsInPayload() {
		$payloadWithMbids = [
			'listen_type' => 'single',
			'payload' => [
				[
					'track_metadata' => [
						'artist_name' => 'Artist',
						'track_name' => 'Track',
						'additional_info' => [
							'recording_mbid' => '11111111-1111-1111-1111-111111111111',
							'duration' => 180,
						]
					]
				]
			]
		];

		$payloadWithoutMbids = [
			'listen_type' => 'single',
			'payload' => [
				[
					'track_metadata' => [
						'artist_name' => 'Artist',
						'track_name' => 'Track',
						'additional_info' => [
							'duration' => 180,
						]
					]
				]
			]
		];

		$this->assertTrue($this->scrobbler->hasMbidsInPayload($payloadWithMbids));
		$this->assertFalse($this->scrobbler->hasMbidsInPayload($payloadWithoutMbids));
	}

	public function testStripMbidsFromPayload() {
		$payload = [
			'listen_type' => 'single',
			'payload' => [
				[
					'track_metadata' => [
						'artist_name' => 'Artist',
						'track_name' => 'Track',
						'release_name' => 'Album',
						'additional_info' => [
							'recording_mbid' => '11111111-1111-1111-1111-111111111111',
							'track_mbid' => '22222222-2222-2222-2222-222222222222',
							'release_mbid' => '33333333-3333-3333-3333-333333333333',
							'release_group_mbid' => '44444444-4444-4444-4444-444444444444',
							'artist_mbids' => ['55555555-5555-5555-5555-555555555555'],
							'duration' => 210,
							'tracknumber' => 3,
						]
					]
				]
			]
		];

		$stripped = $this->scrobbler->stripMbidsFromPayload($payload);
		$metadata = $stripped['payload'][0]['track_metadata'];

		$this->assertEquals('Artist', $metadata['artist_name']);
		$this->assertEquals('Track', $metadata['track_name']);
		$this->assertEquals('Album', $metadata['release_name']);

		$additionalInfo = $metadata['additional_info'];
		$this->assertEquals(210, $additionalInfo['duration']);
		$this->assertEquals(3, $additionalInfo['tracknumber']);
		$this->assertArrayNotHasKey('recording_mbid', $additionalInfo);
		$this->assertArrayNotHasKey('track_mbid', $additionalInfo);
		$this->assertArrayNotHasKey('release_mbid', $additionalInfo);
		$this->assertArrayNotHasKey('release_group_mbid', $additionalInfo);
		$this->assertArrayNotHasKey('artist_mbids', $additionalInfo);
	}

	public function testSubmitListenRetriesWithoutMbidsOnFailure() {
		// Mock ListenBrainzScrobbler to override execSubmitListen
		$mockScrobbler = $this->getMockBuilder(ListenBrainzScrobbler::class)
			->setConstructorArgs([
				$this->config,
				$this->logger,
				$this->urlGenerator,
				$this->albumBusinessLayer,
				$this->artistBusinessLayer,
				$this->crypto,
				'music'
			])
			->onlyMethods(['execSubmitListen'])
			->getMock();

		$payloadWithMbids = [
			'listen_type' => 'single',
			'payload' => [
				[
					'track_metadata' => [
						'artist_name' => 'Artist',
						'track_name' => 'Track',
						'release_name' => 'Album',
						'additional_info' => [
							'recording_mbid' => '11111111-1111-1111-1111-111111111111',
							'duration' => 210,
						]
					]
				]
			]
		];

		$calls = [];
		$mockScrobbler->expects($this->exactly(2))
			->method('execSubmitListen')
			->willReturnCallback(function (string $sessionKey, array $payload) use (&$calls) {
				$calls[] = $payload;
				if (\count($calls) === 1) {
					// First call fails with 400 Bad Request
					return [400, '{"code": 400, "error": "recording_mbid invalid"}', ''];
				}
				// Retry call succeeds
				return [200, '{"status": "ok"}', ''];
			});

		$reflection = new \ReflectionClass(ListenBrainzScrobbler::class);
		$method = $reflection->getMethod('submitListen');
		$method->setAccessible(true);

		$method->invoke($mockScrobbler, 'test-token', $payloadWithMbids);

		$this->assertCount(2, $calls);
		// First call had recording_mbid
		$this->assertArrayHasKey('recording_mbid', $calls[0]['payload'][0]['track_metadata']['additional_info']);
		// Second call stripped recording_mbid, keeping duration and artist/track/release strings
		$this->assertArrayNotHasKey('recording_mbid', $calls[1]['payload'][0]['track_metadata']['additional_info']);
		$this->assertEquals(210, $calls[1]['payload'][0]['track_metadata']['additional_info']['duration']);
		$this->assertEquals('Artist', $calls[1]['payload'][0]['track_metadata']['artist_name']);
		$this->assertEquals('Track', $calls[1]['payload'][0]['track_metadata']['track_name']);
		$this->assertEquals('Album', $calls[1]['payload'][0]['track_metadata']['release_name']);
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
