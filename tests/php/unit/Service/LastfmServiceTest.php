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
		$this->assertEquals('track', LastfmService::normalizeStringLoose('Track (feat. Guest Artist)'));
		$this->assertEquals('dont stop believin', LastfmService::normalizeStringLoose("Don't Stop Believin'"));
	}

	public function testSyncUserListenCountsMissingApiKey() {
		$config = $this->getMockBuilder(IConfig::class)->getMock();
		$config->expects($this->any())
			->method('getSystemValue')
			->with('music.lastfm_api_key', '')
			->will($this->returnValue(''));

		$service = new LastfmService(
			$config,
			$this->albumBusinessLayer,
			$this->artistBusinessLayer,
			$this->trackBusinessLayer,
			$this->logger
		);

		$result = $service->syncUserListenCounts('user1', 'lastfm_user');
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('API key', $result['message']);
	}

	public function testSyncUserListenCountsMissingUsername() {
		$this->config->expects($this->any())
			->method('getUserValue')
			->with('user1', 'music', 'lastfm.username')
			->will($this->returnValue(null));

		$result = $this->service->syncUserListenCounts('user1', null);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('username', $result['message']);
	}

	public function testLimitMostListenedPlaylistNotFound() {
		$this->config->expects($this->any())
			->method('getUserValue')
			->with('user1', 'music', 'lastfm.playlist.most_listened', '0')
			->will($this->returnValue('0'));

		// Mock server container for PlaylistBusinessLayer
		$playlistBL = $this->getMockBuilder(\OCA\Music\BusinessLayer\PlaylistBusinessLayer::class)
			->disableOriginalConstructor()
			->getMock();
		$playlistBL->expects($this->any())
			->method('findAllByName')
			->with('Most Listened (Last.fm)', 'user1')
			->will($this->returnValue([]));

		$container = $this->getMockBuilder(\OCP\IContainer::class)->getMock();
		$container->expects($this->any())
			->method('query')
			->with(\OCA\Music\BusinessLayer\PlaylistBusinessLayer::class)
			->will($this->returnValue($playlistBL));
		\OC::$server = $container;

		$result = $this->service->limitMostListenedPlaylist('user1', 200);
		$this->assertFalse($result['found']);
		$this->assertEquals(0, $result['new_count']);
	}

	public function testSavePlaylistToListenBrainzFolder() {
		$userId = 'user1';
		$title = 'Recommendations 2026-10-22';
		$tracks = [
			[
				'title' => 'Song A',
				'artist' => 'Artist A',
				'duration' => 200,
				'track_id' => 1,
			],
			[
				'title' => 'Song B',
				'artist' => 'Artist B',
				'duration' => -1,
				'track_id' => null,
			],
		];

		// Mock track 1 in TrackBusinessLayer
		$track1 = new \OCA\Music\Db\Track();
		$track1->setId(1);
		$track1->setTitle('Song A');
		$track1->setFileId(101);

		$this->trackBusinessLayer->expects($this->any())
			->method('find')
			->with(1, $userId)
			->will($this->returnValue($track1));

		// Mock Folder and File
		$fileMock = $this->getMockBuilder(\OCP\Files\File::class)->getMock();
		$writtenContent = '';
		$fileMock->expects($this->once())
			->method('putContent')
			->will($this->returnCallback(function($content) use (&$writtenContent) {
				$writtenContent = $content;
				return true;
			}));

		$lbFolderMock = $this->getMockBuilder(\OCP\Files\Folder::class)->getMock();
		$lbFolderMock->expects($this->any())->method('getPath')->will($this->returnValue('/user1/files/ListenBrainz'));
		$lbFolderMock->expects($this->any())->method('nodeExists')->with('Recommendations 2026-10-22.m3u')->will($this->returnValue(false));
		$lbFolderMock->expects($this->once())->method('newFile')->with('Recommendations 2026-10-22.m3u')->will($this->returnValue($fileMock));

		$musicFileNode = $this->getMockBuilder(\OCP\Files\File::class)->getMock();
		$musicFileNode->expects($this->any())->method('getPath')->will($this->returnValue('/user1/files/Music/Artist A/Song A.mp3'));

		$userFolderMock = $this->getMockBuilder(\OCP\Files\Folder::class)->getMock();
		$userFolderMock->expects($this->any())->method('nodeExists')->with('ListenBrainz')->will($this->returnValue(true));
		$userFolderMock->expects($this->any())->method('get')->with('ListenBrainz')->will($this->returnValue($lbFolderMock));
		$userFolderMock->expects($this->any())->method('getById')->with(101)->will($this->returnValue([$musicFileNode]));

		$serverMock = $this->getMockBuilder(\OCP\IServerContainer::class)->getMock();
		$serverMock->expects($this->any())->method('getUserFolder')->with($userId)->will($this->returnValue($userFolderMock));
		\OC::$server = $serverMock;

		$success = $this->service->savePlaylistToListenBrainzFolder($userId, $title, $tracks);
		$this->assertTrue($success);
		$this->assertStringContainsString("#EXTM3U\n#EXTENC: UTF-8", $writtenContent);
		$this->assertStringContainsString("#EXTINF:200,Artist A - Song A", $writtenContent);
		$this->assertStringContainsString("../Music/Artist A/Song A.mp3", $writtenContent);
		$this->assertStringContainsString("#EXTINF:-1,Artist B - Song B", $writtenContent);
		$this->assertStringContainsString("missing/Artist B - Song B.mp3", $writtenContent);
	}

	public function testSyncStationPlaylistsDailySkip() {
		$userId = 'user1';
		$now = \time();

		$this->config->expects($this->any())
			->method('getUserValue')
			->will($this->returnCallback(function($u, $app, $key, $default = null) use ($now) {
				if ($key === 'lastfm.playlist.last_station_sync') {
					return (string)$now;
				}
				if ($key === 'lastfm.username') {
					return 'testuser';
				}
				return $default;
			}));

		$result = $this->service->syncStationPlaylists($userId, null, false);
		$this->assertTrue($result['success']);
		$this->assertTrue($result['skipped']);
		$this->assertStringContainsString('already synced today', $result['message']);
	}

	public function testSyncStationPlaylistsMissingUsername() {
		$userId = 'user1';

		$this->config->expects($this->any())
			->method('getUserValue')
			->will($this->returnCallback(function($u, $app, $key, $default = null) {
				if ($key === 'lastfm.playlist.last_station_sync') {
					return '0';
				}
				if ($key === 'lastfm.username') {
					return null;
				}
				return $default;
			}));

		$result = $this->service->syncStationPlaylists($userId, null, true);
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('username', $result['message']);
	}

	public function testCleanupOldStationPlaylists() {
		$userId = 'user1';
		$now = \time();
		$tenDaysAgo = (new \DateTime())->setTimestamp($now - 10 * 86400)->format('Y-m-d');
		$twoDaysAgo = (new \DateTime())->setTimestamp($now - 2 * 86400)->format('Y-m-d');

		$oldPlaylist = \OCA\Music\Db\Playlist::fromRow([
			'id' => 10,
			'name' => "Recommendations {$tenDaysAgo} (Last.fm)",
			'track_ids' => '1,2,3',
		]);

		$recentPlaylist = \OCA\Music\Db\Playlist::fromRow([
			'id' => 20,
			'name' => "Mix {$twoDaysAgo} (Last.fm)",
			'track_ids' => '4,5',
		]);

		$unrelatedPlaylist = \OCA\Music\Db\Playlist::fromRow([
			'id' => 30,
			'name' => 'Most Listened (Last.fm)',
			'track_ids' => '6,7,8',
		]);

		$playlistBL = $this->getMockBuilder(\OCA\Music\BusinessLayer\PlaylistBusinessLayer::class)
			->disableOriginalConstructor()
			->getMock();
		$playlistBL->expects($this->any())
			->method('findAll')
			->with($userId)
			->will($this->returnValue([$oldPlaylist, $recentPlaylist, $unrelatedPlaylist]));

		// Only oldPlaylist (ID 10) should be deleted!
		$playlistBL->expects($this->once())
			->method('delete')
			->with(10, $userId);

		$container = $this->getMockBuilder(\OCP\IServerContainer::class)->getMock();
		$container->expects($this->any())
			->method('query')
			->with(\OCA\Music\BusinessLayer\PlaylistBusinessLayer::class)
			->will($this->returnValue($playlistBL));
		\OC::$server = $container;

		$this->config->expects($this->any())
			->method('getUserKeys')
			->with($userId, 'music')
			->will($this->returnValue(["lastfm.playlist.recommended.{$tenDaysAgo}", "lastfm.playlist.mix.{$twoDaysAgo}"]));

		$result = $this->service->cleanupOldStationPlaylists($userId, 7, false);
		$this->assertCount(1, $result['deleted_playlists']);
		$this->assertEquals(10, $result['deleted_playlists'][0]['id']);
		$this->assertEquals("Recommendations {$tenDaysAgo} (Last.fm)", $result['deleted_playlists'][0]['name']);
	}
}
