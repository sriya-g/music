<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2020 - 2026
 */

namespace OCA\Music\Service;

use OCA\Music\AppFramework\BusinessLayer\BusinessLayerException;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\BusinessLayer\ArtistBusinessLayer;
use OCA\Music\BusinessLayer\PlaylistBusinessLayer;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Db\Artist;
use OCA\Music\Db\MatchMode;
use OCA\Music\Db\Track;
use OCA\Music\Utility\FilesUtil;
use OCA\Music\Utility\HttpUtil;
use OCA\Music\Utility\StringUtil;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IConfig;

class LastfmService {

	private const LASTFM_URL = 'https://ws.audioscrobbler.com/2.0/';

	private string $apiKey;

	public function __construct(
		private IConfig $config,
		private AlbumBusinessLayer $albumBusinessLayer,
		private ArtistBusinessLayer $artistBusinessLayer,
		private TrackBusinessLayer $trackBusinessLayer,
		private Logger $logger,
		private ?PlaylistBusinessLayer $playlistBusinessLayer = null,
	) {
		$this->apiKey = (string)$config->getSystemValue('music.lastfm_api_key', '');
	}

	private function getPlaylistBusinessLayer() : PlaylistBusinessLayer {
		if ($this->playlistBusinessLayer !== null) {
			return $this->playlistBusinessLayer;
		}
		return \OC::$server->query(PlaylistBusinessLayer::class);
	}

	/**
	 * @throws BusinessLayerException if artist with the given ID is not found
	 */
	public function getArtistInfo(int $artistId, string $userId) : array {
		$artist = $this->artistBusinessLayer->find($artistId, $userId);

		if ($artist->getName() === null) {
			return $this->errorResponse("Can't get details for an unknown artist");
		} else {
			$result = $this->getInfoFromLastFm([
				'method' => 'artist.getInfo',
				'artist' => $artist->getName(),
				'mbid'   => $artist->getMbid(),
			]);

			// add ID to those similar artists which can be found from the library
			$similar = $result['artist']['similar']['artist'] ?? null;
			if ($similar !== null) {
				$result['artist']['similar']['artist'] = \array_map(function ($lastfmArtist) use ($userId) {
					$matching = $this->artistBusinessLayer->findAllByName($lastfmArtist['name'], $userId);
					if (!empty($matching)) {
						$lastfmArtist['id'] = $matching[0]->getId();
					}
					return $lastfmArtist;
				}, $similar);
			}

			return $result;
		}
	}

	/**
	 * @throws BusinessLayerException if album with the given ID is not found
	 */
	public function getAlbumInfo(int $albumId, string $userId) : array {
		$album = $this->albumBusinessLayer->find($albumId, $userId);

		if ($album->getName() === null) {
			return $this->errorResponse("Can't get details for an unknown album");
		} else {
			return $this->getInfoFromLastFm([
				'method' => 'album.getInfo',
				'artist' => $album->getAlbumArtistName(),
				'album'  => $album->getName(),
				'mbid'   => $album->getMbid(),
			]);
		}
	}

	/**
	 * @throws BusinessLayerException if track with the given ID is not found
	 */
	public function getTrackInfo(int $trackId, string $userId) : array {
		$track = $this->trackBusinessLayer->find($trackId, $userId);
		return $this->findTrackInfo($track->getTitle(), $track->getArtistName() ?? '', $track->getMbidRelTrack());
	}

	public function findTrackInfo(string $trackTitle, string $artistName, ?string $mbid = null) : array {
		return $this->getInfoFromLastFm([
			'method'      => 'track.getInfo',
			'artist'      => $artistName,
			'track'       => $trackTitle,
			'mbid'        => $mbid,
			'autocorrect' => 1,
		]);
	}

	/**
	 * Get artists from the user's library similar to the given artist
	 * @param bool $includeNotPresent When true, the result may include also artists which
	 *                                are not found from the user's music library. Such
	 *                                artists have many fields including `id` set as null.
	 * @return Artist[]
	 * @throws BusinessLayerException if artist with the given ID is not found
	 */
	public function getSimilarArtists(int $artistId, string $userId, $includeNotPresent = false) : array {
		$artist = $this->artistBusinessLayer->find($artistId, $userId);

		$similarOnLastfm = $this->getInfoFromLastFm([
			'method' => 'artist.getSimilar',
			'artist' => $artist->getName(),
			'mbid'   => $artist->getMbid(),
		]);

		$result = [];
		$similarArr = $similarOnLastfm['similarartists']['artist'] ?? null;
		if ($similarArr !== null) {
			foreach ($similarArr as $lastfmArtist) {
				$matchingLibArtists = $this->artistBusinessLayer->findAllByName($lastfmArtist['name'], $userId);

				if (!empty($matchingLibArtists)) {
					foreach ($matchingLibArtists as $matchArtist) { // loop although there really shouldn't be more than one
						$matchArtist->setLastfmUrl($lastfmArtist['url']);
					}
					$result = \array_merge($result, $matchingLibArtists);
				} elseif ($includeNotPresent) {
					$unfoundArtist = new Artist();
					$unfoundArtist->setName($lastfmArtist['name'] ?? null);
					$unfoundArtist->setMbid($lastfmArtist['mbid'] ?? null);
					$unfoundArtist->setLastfmUrl($lastfmArtist['url'] ?? null);
					$result[] = $unfoundArtist;
				}
			}
		}

		return $result;
	}

	/**
	 * Get tracks from the user's library similar to the given track
	 * @return Track[]
	 * @throws BusinessLayerException if track with the given ID is not found
	 */
	public function getSimilarTracks(int $trackId, string $userId) : array {
		$track = $this->trackBusinessLayer->find($trackId, $userId);

		$similarOnLastfm = $this->getInfoFromLastFm([
			'method' => 'track.getSimilar',
			'track'  => $track->getTitle(),
			'artist' => $track->getArtistName(),
			'mbid'   => $track->getMbidRelTrack(),
		]);

		$result = [];
		$similarArr = $similarOnLastfm['similartracks']['track'] ?? null;
		if ($similarArr !== null) {
			foreach ($similarArr as $lastfmTrack) {
				$matchingLibTracks = $this->trackBusinessLayer->findAllByNameArtistOrAlbum(
					$lastfmTrack['name'], $lastfmTrack['artist']['name'], null, $userId);
				$result = \array_merge($result, $matchingLibTracks);
			}
		}

		return $result;
	}

	/**
	 * Get artist tracks from the user's library, sorted by their popularity on Last.fm
	 * @param int|string $artistIdOrName Either the ID of the artist or the artist's name written exactly
	 *                                   like in the DB. Any integer-typed value is treated as an ID and
	 *                                   string-typed value as a name.
	 * @param int $maxCount Number of tracks to request from Last.fm. Note that the function may return much
	 *                      less tracks if the top tracks from Last.fm are not present in the user's library.
	 * @return Track[]
	 */
	public function getTopTracks(int|string $artistIdOrName, string $userId, int $maxCount) : array {
		$foundTracks = [];

		if (\is_integer($artistIdOrName)) {
			$artist = $this->artistBusinessLayer->find($artistIdOrName, $userId);
		} else {
			$artist = $this->artistBusinessLayer->findAllByName($artistIdOrName, $userId, MatchMode::Exact, /*$limit=*/1)[0] ?? null;
		}

		if ($artist !== null) {
			$lastfmResult = $this->getInfoFromLastFm([
				'method' => 'artist.getTopTracks',
				'artist' => $artist->getName(),
				'mbid'   => $artist->getMbid(),
				'limit'  => (string)$maxCount,
			]);
			$topTracksOnLastfm = $lastfmResult['toptracks']['track'] ?? null;

			if ($topTracksOnLastfm !== null) {
				$libTracks = $this->trackBusinessLayer->findAllByArtist($artist->getId(), $userId);

				foreach ($topTracksOnLastfm as $lastfmTrack) {
					foreach ($libTracks as $libTrack) {
						if (\mb_strtolower($lastfmTrack['name']) === \mb_strtolower($libTrack->getTitle())) {
							$foundTracks[] = $libTrack;
							break;
						}
					}
				}
			}
		}

		return $foundTracks;
	}

	/**
	 * Get user top tracks from Last.fm
	 * @param string $username Last.fm username
	 * @param string $period overall | 7day | 1month | 3month | 6month | 12month
	 * @param int $limit Max items per page (up to 1000)
	 * @param int $page Page number
	 * @return array
	 */
	public function getUserTopTracks(string $username, string $period = 'overall', int $limit = 1000, int $page = 1) : array {
		return $this->getInfoFromLastFm([
			'method' => 'user.getTopTracks',
			'user'   => $username,
			'period' => $period,
			'limit'  => (string)$limit,
			'page'   => (string)$page,
		]);
	}

	/**
	 * Get user loved tracks from Last.fm
	 * @param string $username Last.fm username
	 * @param int $limit Max items per page (up to 1000)
	 * @param int $page Page number
	 * @return array
	 */
	public function getUserLovedTracks(string $username, int $limit = 1000, int $page = 1) : array {
		return $this->getInfoFromLastFm([
			'method' => 'user.getLovedTracks',
			'user'   => $username,
			'limit'  => (string)$limit,
			'page'   => (string)$page,
		]);
	}

	public static function normalizeString(string $str) : string {
		$str = \mb_strtolower(\trim($str));
		$str = (string)\preg_replace('/\s+/', ' ', $str);
		return $str;
	}

	public static function normalizeStringLoose(string $str) : string {
		$str = self::normalizeString($str);
		$str = (string)\preg_replace('/\s*[\(\[][^\)\]]*(remaster|live|version|edit|feat|ft\.)[^\)\]]*[\)\]]/i', '', $str);
		$str = (string)\preg_replace('/[^\p{L}\p{N}\s]/u', '', $str);
		$str = (string)\preg_replace('/\s+/', ' ', $str);
		return \trim($str);
	}

	/**
	 * Sync Last.fm listen counts (playcount) and loved tracks into Nextcloud Music for the user.
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string|null $username Optional Last.fm username (falls back to connected Last.fm account)
	 * @param string $period overall | 7day | 1month | 3month | 6month | 12month
	 * @param int $maxTracks Maximum number of tracks to fetch from Last.fm
	 * @param bool $createPlaylist Whether to create/update a "Most Listened (Last.fm)" playlist
	 * @param bool $onlyIfGreater Update only if Last.fm play count is greater than current play count
	 * @param callable|null $logCallback Optional callback for progress logging fn(string $msg)
	 * @param int $playlistLimit Maximum number of tracks in the playlist (default 200)
	 * @return array Result summary
	 */
	public function syncUserListenCounts(
		string $userId,
		?string $username = null,
		string $period = 'overall',
		int $maxTracks = 5000,
		bool $createPlaylist = false,
		bool $onlyIfGreater = true,
		?callable $logCallback = null,
		int $playlistLimit = 200
	) : array {
		$log = function(string $msg) use ($logCallback) {
			$this->logger->info("[Last.fm Sync] " . $msg);
			if ($logCallback !== null) {
				$logCallback($msg);
			}
		};

		@\set_time_limit(300);

		$apiKey = $this->getApiKey();
		if (empty($apiKey)) {
			$log("Last.fm API key is not configured");
			return [
				'success' => false,
				'message' => "Last.fm API key is not configured. Please set 'music.lastfm_api_key' in your Nextcloud config."
			];
		}

		if (empty($username)) {
			// Try getting username from config
			$username = $this->config->getUserValue($userId, 'music', 'lastfm.username');
		}

		if (empty($username)) {
			// Try getting from ExternalScrobbler
			try {
				/** @var \OCA\Music\AppInfo\Application $app */
				$app = \OC::$server->query(\OCA\Music\AppInfo\Application::class);
				$scrobblers = $app->get('externalScrobblers');
				if (\is_array($scrobblers)) {
					foreach ($scrobblers as $scrobbler) {
						if ($scrobbler instanceof Scrobbling\ExternalScrobbler && $scrobbler->getIdentifier() === 'lastfm') {
							$username = $scrobbler->getUsername($userId);
							break;
						}
					}
				}
			} catch (\Throwable) {
				try {
					$scrobblers = \OC::$server->query('externalScrobblers');
					if (\is_array($scrobblers)) {
						foreach ($scrobblers as $scrobbler) {
							if ($scrobbler instanceof Scrobbling\ExternalScrobbler && $scrobbler->getIdentifier() === 'lastfm') {
								$username = $scrobbler->getUsername($userId);
								break;
							}
						}
					}
				} catch (\Throwable) {
					// Fallback failed
				}
			}
		}

		if (empty($username)) {
			$log("No Last.fm username available for user {$userId}");
			return [
				'success' => false,
				'message' => "No Last.fm username found for user {$userId}. Please specify a username or connect Last.fm in Settings."
			];
		}

		$log("Starting listen counts and loved tracks sync for user '{$userId}' using Last.fm user '{$username}' (period: {$period})");

		// Fetch all local tracks for the user
		$localTracks = $this->trackBusinessLayer->findAll($userId);
		if (empty($localTracks)) {
			$log("User {$userId} has no tracks in library");
			return [
				'success' => true,
				'username' => $username,
				'total_fetched' => 0,
				'matched' => 0,
				'updated' => 0,
				'loved_fetched' => 0,
				'loved_matched' => 0,
				'loved_updated' => 0,
				'starred_track_ids' => [],
				'message' => 'No tracks in local library.'
			];
		}

		// Index local tracks
		$tracksByMbid = [];
		$tracksByName = [];
		$tracksByNameLoose = [];

		foreach ($localTracks as $track) {
			$mbid = $track->getMbid();
			if (!empty($mbid)) {
				$tracksByMbid[$mbid][] = $track;
			}
			$mbidRel = $track->getMbidRelTrack();
			if (!empty($mbidRel)) {
				$tracksByMbid[$mbidRel][] = $track;
			}

			$artist = $track->getArtistName() ?? '';
			$title = $track->getTitle() ?? '';

			if (!empty($artist) && !empty($title)) {
				$key = self::normalizeString($artist) . '|' . self::normalizeString($title);
				$tracksByName[$key][] = $track;

				$keyLoose = self::normalizeStringLoose($artist) . '|' . self::normalizeStringLoose($title);
				$tracksByNameLoose[$keyLoose][] = $track;
			}
		}

		$findMatches = function(?string $mbid, string $artist, string $title) use (&$tracksByMbid, &$tracksByName, &$tracksByNameLoose) : array {
			$matched = [];
			if ($mbid && isset($tracksByMbid[$mbid])) {
				$matched = $tracksByMbid[$mbid];
			}
			if (empty($matched)) {
				$key = self::normalizeString($artist) . '|' . self::normalizeString($title);
				if (isset($tracksByName[$key])) {
					$matched = $tracksByName[$key];
				} else {
					$keyLoose = self::normalizeStringLoose($artist) . '|' . self::normalizeStringLoose($title);
					if (isset($tracksByNameLoose[$keyLoose])) {
						$matched = $tracksByNameLoose[$keyLoose];
					}
				}
			}
			return $matched;
		};

		$page = 1;
		$totalFetched = 0;
		$matchedTrackIds = [];
		$orderedMatchedTrackIds = [];
		$updatedCount = 0;

		do {
			$limit = \min(1000, $maxTracks - $totalFetched);
			if ($limit <= 0) {
				break;
			}

			$response = $this->getUserTopTracks($username, $period, $limit, $page);

			if (isset($response['error'])) {
				$errorMsg = $response['message'] ?? "Error code {$response['error']}";
				$log("Last.fm API error: {$errorMsg}");
				return [
					'success' => false,
					'message' => "Last.fm API error: {$errorMsg}"
				];
			}

			if (isset($response['connection_ok']) && $response['connection_ok'] === false) {
				$statusCode = $response['status_code'] ?? 0;
				$statusMsg = $response['status_msg'] ?? 'Unknown network error';
				$errorMsg = "HTTP {$statusCode}: {$statusMsg}";
				$log("Last.fm connection error: {$errorMsg}");
				return [
					'success' => false,
					'message' => "Failed to connect to Last.fm ({$errorMsg})"
				];
			}

			$tracks = $response['toptracks']['track'] ?? [];
			if (empty($tracks)) {
				break;
			}
			if (isset($tracks['name'])) {
				$tracks = [$tracks];
			}

			foreach ($tracks as $lastfmTrack) {
				$totalFetched++;
				$title = (string)($lastfmTrack['name'] ?? '');
				$artist = (string)($lastfmTrack['artist']['name'] ?? '');
				$playCount = (int)($lastfmTrack['playcount'] ?? 0);
				$mbid = !empty($lastfmTrack['mbid']) ? (string)$lastfmTrack['mbid'] : null;

				if (empty($title) || $playCount <= 0) {
					continue;
				}

				$matched = $findMatches($mbid, $artist, $title);

				if (!empty($matched)) {
					foreach ($matched as $libTrack) {
						$trackId = $libTrack->getId();
						if (!isset($matchedTrackIds[$trackId])) {
							$matchedTrackIds[$trackId] = true;
							$orderedMatchedTrackIds[] = $trackId;
						}

						$currentCount = $libTrack->getPlayCount();
						$shouldUpdate = $onlyIfGreater ? ($playCount > $currentCount) : ($playCount !== $currentCount);

						if ($shouldUpdate) {
							$this->trackBusinessLayer->updatePlayCount($trackId, $userId, $playCount);
							$libTrack->setPlayCount($playCount);
							$updatedCount++;
						}
					}
				}

				if ($totalFetched >= $maxTracks) {
					break;
				}
			}

			$totalPages = (int)($response['toptracks']['@attr']['totalPages'] ?? 1);
			$page++;
			if ($page > $totalPages || $totalFetched >= $maxTracks) {
				break;
			}
			\usleep(200000); // 200ms rate limit polite delay
		} while (true);

		$matchedCount = \count($matchedTrackIds);
		$log("Fetched {$totalFetched} top tracks from Last.fm, matched {$matchedCount} in local library, updated {$updatedCount} play counts");

		// Sync loved tracks from Last.fm
		$lovedPage = 1;
		$lovedFetched = 0;
		$lovedMatchedTrackIds = [];
		$trackIdsToStar = [];

		do {
			$lovedLimit = \min(1000, $maxTracks - $lovedFetched);
			if ($lovedLimit <= 0) {
				break;
			}

			$lovedResponse = $this->getUserLovedTracks($username, $lovedLimit, $lovedPage);

			if (isset($lovedResponse['error'])) {
				$errorMsg = $lovedResponse['message'] ?? "Error code {$lovedResponse['error']}";
				$log("Last.fm API error fetching loved tracks: {$errorMsg}");
				break;
			}

			if (isset($lovedResponse['connection_ok']) && $lovedResponse['connection_ok'] === false) {
				$statusCode = $lovedResponse['status_code'] ?? 0;
				$statusMsg = $lovedResponse['status_msg'] ?? 'Unknown network error';
				$log("Last.fm connection error fetching loved tracks: HTTP {$statusCode}: {$statusMsg}");
				break;
			}

			$lovedTracks = $lovedResponse['lovedtracks']['track'] ?? [];
			if (empty($lovedTracks)) {
				break;
			}
			if (isset($lovedTracks['name'])) {
				$lovedTracks = [$lovedTracks];
			}

			foreach ($lovedTracks as $lastfmTrack) {
				$lovedFetched++;
				$title = (string)($lastfmTrack['name'] ?? '');
				$artist = (string)($lastfmTrack['artist']['name'] ?? $lastfmTrack['artist']['#text'] ?? '');
				$mbid = !empty($lastfmTrack['mbid']) ? (string)$lastfmTrack['mbid'] : null;

				if (empty($title)) {
					continue;
				}

				$matched = $findMatches($mbid, $artist, $title);

				if (!empty($matched)) {
					foreach ($matched as $libTrack) {
						$trackId = $libTrack->getId();
						$lovedMatchedTrackIds[$trackId] = true;
						if (empty($libTrack->getStarred())) {
							$trackIdsToStar[$trackId] = $trackId;
							$libTrack->setStarred((new \DateTime())->format('Y-m-d H:i:s'));
						}
					}
				}

				if ($lovedFetched >= $maxTracks) {
					break;
				}
			}

			$lovedTotalPages = (int)($lovedResponse['lovedtracks']['@attr']['totalPages'] ?? 1);
			$lovedPage++;
			if ($lovedPage > $lovedTotalPages || $lovedFetched >= $maxTracks) {
				break;
			}
			\usleep(200000);
		} while (true);

		$starredTrackIds = \array_values($trackIdsToStar);
		$lovedUpdatedCount = \count($starredTrackIds);
		$lovedMatchedCount = \count($lovedMatchedTrackIds);

		if (!empty($starredTrackIds)) {
			foreach (\array_chunk($starredTrackIds, 500) as $chunk) {
				$this->trackBusinessLayer->setStarred($chunk, $userId);
			}
		}

		$log("Fetched {$lovedFetched} loved tracks from Last.fm, matched {$lovedMatchedCount} in local library, starred {$lovedUpdatedCount} new tracks");

		$playlistId = null;
		if ($createPlaylist && !empty($orderedMatchedTrackIds)) {
			try {
				$playlistBusinessLayer = $this->getPlaylistBusinessLayer();
				$configKey = 'lastfm.playlist.most_listened';
				$existingPlaylistId = (int)$this->config->getUserValue($userId, 'music', $configKey, '0');
				$playlist = null;

				if ($existingPlaylistId > 0) {
					try {
						$playlist = $playlistBusinessLayer->find($existingPlaylistId, $userId);
					} catch (\Throwable) {
						$playlist = null;
					}
				}

				if (!$playlist) {
					$playlist = $playlistBusinessLayer->create('Most Listened (Last.fm)', $userId);
					$this->config->setUserValue($userId, 'music', $configKey, (string)$playlist->getId());
				}

				$playlistTracks = \array_slice($orderedMatchedTrackIds, 0, $playlistLimit);
				$playlistBusinessLayer->setTracks($playlistTracks, $playlist->getId(), $userId);
				$playlistId = $playlist->getId();
				$playlistTrackCount = \count($playlistTracks);
				$log("Created/updated playlist 'Most Listened (Last.fm)' (ID: {$playlistId}) with {$playlistTrackCount} tracks");
			} catch (\Throwable $e) {
				$log("Failed to create/update playlist: " . $e->getMessage());
			}
		}

		return [
			'success' => true,
			'username' => $username,
			'total_fetched' => $totalFetched,
			'matched' => $matchedCount,
			'updated' => $updatedCount,
			'loved_fetched' => $lovedFetched,
			'loved_matched' => $lovedMatchedCount,
			'loved_updated' => $lovedUpdatedCount,
			'starred_track_ids' => $starredTrackIds,
			'playlist_id' => $playlistId,
			'playlist_track_count' => $playlistTrackCount ?? 0,
			'message' => "Successfully synced listen counts and loved tracks: {$updatedCount} listen counts updated ({$matchedCount} matched from {$totalFetched} Last.fm top tracks), {$lovedUpdatedCount} loved tracks starred ({$lovedMatchedCount} matched from {$lovedFetched} Last.fm loved tracks)."
		];
	}

	/**
	 * Fetch tracks from a Last.fm station endpoint (e.g. recommended or mix).
	 *
	 * @param string $stationUrl
	 * @return array<int, array{title: string, artist: string, duration: int}>
	 */
	public function fetchStationTracks(string $stationUrl) : array {
		$ch = \curl_init();
		if (!$ch) {
			$this->logger->error("LastfmService: Failed to initialize curl handle for {$stationUrl}");
			return [];
		}

		\curl_setopt($ch, \CURLOPT_URL, $stationUrl);
		\curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, 15);
		\curl_setopt($ch, \CURLOPT_TIMEOUT, 30);
		\curl_setopt($ch, \CURLOPT_FOLLOWLOCATION, true);
		\curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($ch, \CURLOPT_USERAGENT, 'NextcloudMusicApp/1.0');
		\curl_setopt($ch, \CURLOPT_HTTPHEADER, [
			'Accept: application/json',
		]);

		$response = \curl_exec($ch);
		$httpCode = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
		$curlError = \curl_error($ch);
		\curl_close($ch);

		if ($response === false || $httpCode !== 200) {
			$this->logger->warning("LastfmService: Failed to fetch station from {$stationUrl} (HTTP {$httpCode}, cURL Error: {$curlError})");
			return [];
		}

		$data = \json_decode((string)$response, true);
		if (!isset($data['playlist']) || !\is_array($data['playlist'])) {
			$this->logger->warning("LastfmService: Station response from {$stationUrl} contains no 'playlist' array");
			return [];
		}

		$tracks = [];
		foreach ($data['playlist'] as $item) {
			$title = (string)($item['name'] ?? $item['_name'] ?? '');
			$artists = $item['artists'] ?? [];
			$artist = '';
			if (\is_array($artists) && !empty($artists)) {
				$firstArtist = $artists[0];
				$artist = (string)($firstArtist['name'] ?? $firstArtist['_name'] ?? '');
			}

			if (empty($title) || empty($artist)) {
				continue;
			}

			$duration = isset($item['duration']) && \is_numeric($item['duration']) && $item['duration'] > 0
				? (int)$item['duration']
				: -1;

			$tracks[] = [
				'title' => $title,
				'artist' => $artist,
				'duration' => $duration,
			];
		}

		return $tracks;
	}

	/**
	 * Save playlist as M3U file in the user's ListenBrainz folder, reusing ListenBrainz playlist logic.
	 *
	 * @param string $userId
	 * @param string $title Playlist title (without extension)
	 * @param array<int, array{title: string, artist: string, duration: int, track_id: ?int}> $tracks
	 * @return bool
	 */
	public function savePlaylistToListenBrainzFolder(string $userId, string $title, array $tracks) : bool {
		try {
			$userFolder = \OC::$server->getUserFolder($userId);
			if (!$userFolder->nodeExists('ListenBrainz')) {
				$userFolder->newFolder('ListenBrainz');
			}
			$lbFolder = $userFolder->get('ListenBrainz');
			if ($lbFolder instanceof Folder) {
				$sanitizedTitle = (string)\preg_replace('/[^a-zA-Z0-9_\-,. ]/', '', $title);
				$m3uFilename = $sanitizedTitle . '.m3u';

				$file = $lbFolder->nodeExists($m3uFilename)
					? $lbFolder->get($m3uFilename)
					: $lbFolder->newFile($m3uFilename);

				if ($file instanceof File) {
					$m3uContent = "#EXTM3U\n#EXTENC: UTF-8\n";

					foreach ($tracks as $item) {
						$trackTitle = $item['title'] ?? '';
						$artistName = $item['artist'] ?? '';
						if (empty($trackTitle) || empty($artistName)) {
							continue;
						}

						$durationSec = isset($item['duration']) && $item['duration'] > 0 ? (int)$item['duration'] : -1;
						$trackId = $item['track_id'] ?? null;

						$written = false;
						if ($trackId !== null && $trackId > 0) {
							try {
								$foundTrack = $this->trackBusinessLayer->find($trackId, $userId);
								$nodes = $userFolder->getById($foundTrack->getFileId());
								if (\count($nodes) > 0) {
									$trackRelPath = FilesUtil::relativePath($lbFolder->getPath(), $nodes[0]->getPath());
									$m3uContent .= "#EXTINF:{$durationSec},{$artistName} - {$trackTitle}\n";
									$m3uContent .= $trackRelPath . "\n";
									$written = true;
								}
							} catch (\Throwable) {
							}
						}

						if (!$written) {
							$m3uContent .= "#EXTINF:{$durationSec},{$artistName} - {$trackTitle}\n";
							$m3uContent .= 'missing/' . \sprintf('%s - %s.mp3', $artistName, $trackTitle) . "\n";
						}
					}

					$file->putContent($m3uContent);
					$this->logger->info("Last.fm playlist sync: saved M3U playlist to ListenBrainz/{$m3uFilename}");
					return true;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Last.fm playlist sync: failed to save M3U playlist: ' . $e->getMessage());
		}
		return false;
	}

	/**
	 * Delete a file from the user's ListenBrainz folder.
	 *
	 * @param string $userId
	 * @param string $filename
	 * @return bool
	 */
	public function deleteFileFromListenBrainzFolder(string $userId, string $filename) : bool {
		try {
			$userFolder = \OC::$server->getUserFolder($userId);
			if ($userFolder->nodeExists('ListenBrainz')) {
				$lbFolder = $userFolder->get('ListenBrainz');
				if ($lbFolder instanceof Folder && $lbFolder->nodeExists($filename)) {
					$file = $lbFolder->get($filename);
					$file->delete();
					$this->logger->info("Last.fm playlist sync: deleted file ListenBrainz/{$filename}");
					return true;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning("Last.fm playlist sync: failed to delete file ListenBrainz/{$filename}: " . $e->getMessage());
		}
		return false;
	}

	/**
	 * Sync Last.fm station playlists (recommendations and mix) daily for the user.
	 * Also saves M3U playlists to the ListenBrainz folder and creates/updates playlists in Nextcloud.
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string|null $username Optional Last.fm username
	 * @param bool $force Force sync even if playlists were already synced today
	 * @param callable|null $logCallback Optional progress logger fn(string $msg)
	 * @return array Result summary
	 */
	public function syncStationPlaylists(
		string $userId,
		?string $username = null,
		bool $force = false,
		?callable $logCallback = null
	) : array {
		$log = $logCallback ?? fn(string $msg) => $this->logger->info($msg);

		// Check daily interval unless force is specified
		$lastSyncKey = 'lastfm.playlist.last_station_sync';
		$lastSync = (int)$this->config->getUserValue($userId, 'music', $lastSyncKey, '0');
		$now = \time();
		$date = (new \DateTime())->format('Y-m-d');
		$lastSyncDate = $lastSync > 0 ? (new \DateTime())->setTimestamp($lastSync)->format('Y-m-d') : '';

		if (!$force && $lastSync > 0 && $lastSyncDate === $date) {
			$msg = "Station playlists were already synced today ({$date}) for user {$userId}. Skipping.";
			$log($msg);
			return [
				'success' => true,
				'skipped' => true,
				'username' => $username,
				'message' => $msg,
			];
		}

		if (empty($username)) {
			$username = $this->config->getUserValue($userId, 'music', 'lastfm.username');
		}

		if (empty($username)) {
			try {
				/** @var \OCA\Music\AppInfo\Application $app */
				$app = \OC::$server->query(\OCA\Music\AppInfo\Application::class);
				$scrobblers = $app->get('externalScrobblers');
				if (\is_array($scrobblers)) {
					foreach ($scrobblers as $scrobbler) {
						if ($scrobbler instanceof Scrobbling\ExternalScrobbler && $scrobbler->getIdentifier() === 'lastfm') {
							$username = $scrobbler->getUsername($userId);
							break;
						}
					}
				}
			} catch (\Throwable) {
				try {
					$scrobblers = \OC::$server->query('externalScrobblers');
					if (\is_array($scrobblers)) {
						foreach ($scrobblers as $scrobbler) {
							if ($scrobbler instanceof Scrobbling\ExternalScrobbler && $scrobbler->getIdentifier() === 'lastfm') {
								$username = $scrobbler->getUsername($userId);
								break;
							}
						}
					}
				} catch (\Throwable) {
				}
			}
		}

		if (empty($username)) {
			$msg = "No Last.fm username found for user {$userId}. Please specify a username or connect Last.fm in Settings.";
			$log($msg);
			return [
				'success' => false,
				'skipped' => false,
				'message' => $msg,
			];
		}

		$encodedUser = \rawurlencode($username);
		$stations = [
			'recommended' => [
				'base_name' => 'Recommendations',
				'url' => "https://www.last.fm/player/station/user/{$encodedUser}/recommended",
				'config_key_main' => 'lastfm.playlist.recommended',
				'config_key_dated' => "lastfm.playlist.recommended.{$date}",
			],
			'mix' => [
				'base_name' => 'Mix',
				'url' => "https://www.last.fm/player/station/user/{$encodedUser}/mix",
				'config_key_main' => 'lastfm.playlist.mix',
				'config_key_dated' => "lastfm.playlist.mix.{$date}",
			],
		];

		$playlistBusinessLayer = $this->getPlaylistBusinessLayer();
		$syncedPlaylists = [];

		foreach ($stations as $stationType => $stationInfo) {
			$baseName = $stationInfo['base_name'];
			$url = $stationInfo['url'];

			$log("Fetching Last.fm station tracks from {$url}...");
			$stationTracks = $this->fetchStationTracks($url);
			$totalTracks = \count($stationTracks);

			if ($totalTracks === 0) {
				$log("No tracks found or failed to fetch station for {$stationType}.");
				continue;
			}

			$log("Found {$totalTracks} tracks in Last.fm station '{$baseName}'. Matching against local music library...");

			$ncTrackIds = [];
			$tracksForM3u = [];

			foreach ($stationTracks as $stTrack) {
				$trackTitle = $stTrack['title'];
				$artistName = $stTrack['artist'];
				$durationSec = $stTrack['duration'];

				$matchedTrackId = null;
				$foundTracks = $this->trackBusinessLayer->findAllByNameArtistOrAlbum($trackTitle, $artistName, null, $userId);

				if (empty($foundTracks)) {
					// Loose match fallback: strip remaster/live/version suffix
					$cleanTitle = (string)\preg_replace('/\s*[-–]\s*(remaster(ed)?|live|single|edit|version).*/i', '', $trackTitle);
					$cleanTitle = (string)\preg_replace('/\s*[\(\[][^\)\]]*(remaster|live|version|edit|feat|ft\.)[^\)\]]*[\)\]]/i', '', $cleanTitle);
					$cleanTitle = \trim($cleanTitle);
					if (!empty($cleanTitle) && $cleanTitle !== $trackTitle) {
						$foundTracks = $this->trackBusinessLayer->findAllByNameArtistOrAlbum($cleanTitle, $artistName, null, $userId);
					}
				}

				if (!empty($foundTracks)) {
					$matchedTrackId = $foundTracks[0]->getId();
					$ncTrackIds[] = $matchedTrackId;
				}

				$tracksForM3u[] = [
					'title' => $trackTitle,
					'artist' => $artistName,
					'duration' => $durationSec,
					'track_id' => $matchedTrackId,
				];
			}

			$matchedCount = \count($ncTrackIds);
			$log("Matched {$matchedCount} of {$totalTracks} tracks in local library for '{$baseName}'.");

			// 1. Remove undated M3U file and undated playlist if present
			$this->deleteFileFromListenBrainzFolder($userId, "{$baseName}.m3u");
			$undatedPlaylistName = $baseName . ' (Last.fm)';
			$oldMainId = (int)$this->config->getUserValue($userId, 'music', $stationInfo['config_key_main'], '0');
			if ($oldMainId > 0) {
				try {
					$playlistBusinessLayer->delete($oldMainId, $userId);
					$log("Removed undated playlist '{$undatedPlaylistName}' (ID {$oldMainId})");
				} catch (\Throwable) {
				}
				$this->config->deleteUserValue($userId, 'music', $stationInfo['config_key_main']);
			}
			$matching = $playlistBusinessLayer->findAllByName($undatedPlaylistName, $userId);
			foreach ($matching as $matchPl) {
				try {
					$playlistBusinessLayer->delete($matchPl->getId(), $userId);
					$log("Removed undated playlist '{$undatedPlaylistName}' (ID {$matchPl->getId()})");
				} catch (\Throwable) {
				}
			}

			// 2. Save dated snapshot M3U file: "ListenBrainz/{$baseName} {$date}.m3u"
			$savedM3u = $this->savePlaylistToListenBrainzFolder($userId, "{$baseName} {$date}", $tracksForM3u);

			// 3. Create or update dated snapshot playlist: "{$baseName} YYYY-MM-DD (Last.fm)"
			$datedPlaylistName = "{$baseName} {$date} (Last.fm)";
			$datedId = (int)$this->config->getUserValue($userId, 'music', $stationInfo['config_key_dated'], '0');
			$datedPlaylist = null;
			if ($datedId > 0) {
				try {
					$datedPlaylist = $playlistBusinessLayer->find($datedId, $userId);
				} catch (\Throwable) {
					$datedPlaylist = null;
				}
			}
			if (!$datedPlaylist) {
				$matching = $playlistBusinessLayer->findAllByName($datedPlaylistName, $userId);
				if (!empty($matching)) {
					$datedPlaylist = $matching[0];
					$this->config->setUserValue($userId, 'music', $stationInfo['config_key_dated'], (string)$datedPlaylist->getId());
				}
			}
			if (!$datedPlaylist) {
				$datedPlaylist = $playlistBusinessLayer->create($datedPlaylistName, $userId);
				$this->config->setUserValue($userId, 'music', $stationInfo['config_key_dated'], (string)$datedPlaylist->getId());
				$log("Created Nextcloud playlist '{$datedPlaylistName}' (ID {$datedPlaylist->getId()})");
			} else {
				if ($datedPlaylist->getName() !== $datedPlaylistName) {
					$playlistBusinessLayer->rename($datedPlaylistName, $datedPlaylist->getId(), $userId);
				}
				$log("Updating Nextcloud playlist '{$datedPlaylistName}' (ID {$datedPlaylist->getId()})");
			}
			$playlistBusinessLayer->setTracks($ncTrackIds, $datedPlaylist->getId(), $userId);

			$syncedPlaylists[$stationType] = [
				'playlist_id' => $datedPlaylist->getId(),
				'name' => $datedPlaylistName,
				'total_tracks' => $totalTracks,
				'matched_tracks' => $matchedCount,
				'm3u_file' => "ListenBrainz/{$baseName} {$date}.m3u",
				'm3u_saved' => $savedM3u,
			];
		}

		$syncedCount = \count($syncedPlaylists);
		if ($syncedCount > 0) {
			// Update last sync timestamp only when playlists were successfully synced
			$this->config->setUserValue($userId, 'music', $lastSyncKey, (string)$now);

			// Clean up week-old station playlists
			$this->cleanupOldStationPlaylists($userId, 7, false, $logCallback);

			$msg = "Successfully synced {$syncedCount} Last.fm station playlist(s) for user {$userId}.";
			$log($msg);

			return [
				'success' => true,
				'skipped' => false,
				'username' => $username,
				'date' => $date,
				'playlists' => $syncedPlaylists,
				'message' => $msg,
			];
		}

		$msg = "No Last.fm station playlists could be synced for user {$userId}.";
		$log($msg);

		return [
			'success' => false,
			'skipped' => false,
			'username' => $username,
			'date' => $date,
			'playlists' => [],
			'message' => $msg,
		];
	}

	/**
	 * Clean up Last.fm station playlists older than $days (default 7 days).
	 *
	 * @param string $userId Nextcloud user ID
	 * @param int $days Number of days threshold (default 7)
	 * @param bool $dryRun If true, only reports playlists without deleting them
	 * @param callable|null $logCallback Optional progress logger fn(string $msg)
	 * @return array{deleted_playlists: array}
	 */
	public function cleanupOldStationPlaylists(
		string $userId,
		int $days = 7,
		bool $dryRun = false,
		?callable $logCallback = null
	) : array {
		$playlistBusinessLayer = $this->getPlaylistBusinessLayer();
		$log = $logCallback ?? fn(string $msg) => $this->logger->info($msg);

		try {
			$allPlaylists = $playlistBusinessLayer->findAll($userId);
		} catch (\Throwable $e) {
			$log("Failed to fetch playlists for user {$userId}: " . $e->getMessage());
			return ['deleted_playlists' => []];
		}

		$now = \time();
		$cutoffSeconds = $days * 86400;
		$deletedPlaylists = [];

		foreach ($allPlaylists as $playlist) {
			$playlistName = $playlist->getName() ?? '';
			$playlistId = $playlist->getId();

			// Also delete any leftover undated "Recommendations (Last.fm)" or "Mix (Last.fm)" playlists
			if ($playlistName === 'Recommendations (Last.fm)' || $playlistName === 'Mix (Last.fm)') {
				if (!$dryRun) {
					try {
						$playlistBusinessLayer->delete($playlistId, $userId);
						$log("Deleted undated Last.fm playlist '{$playlistName}' (ID {$playlistId})");
						$deletedPlaylists[] = [
							'id' => $playlistId,
							'name' => $playlistName,
							'tracks' => $playlist->getTrackCount(),
							'age_days' => 0,
						];
					} catch (\Throwable $e) {
						$log("Failed to delete playlist '{$playlistName}' (ID {$playlistId}): " . $e->getMessage());
					}
				}
				continue;
			}

			// Only clean up Last.fm station playlists (Recommendations / Mix with a date)
			$isStationPlaylist = (
				\strpos($playlistName, '(Last.fm)') !== false &&
				(\strpos($playlistName, 'Recommendations') !== false || \strpos($playlistName, 'Mix') !== false) &&
				\preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $playlistName)
			);

			if (!$isStationPlaylist) {
				continue;
			}

			$playlistDate = null;
			if (\preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $playlistName, $matches)) {
				$playlistDate = \strtotime($matches[1]);
			}

			if ($playlistDate !== null && $playlistDate > 0) {
				$ageSeconds = $now - $playlistDate;
				if ($ageSeconds > $cutoffSeconds) {
					$ageDays = \round($ageSeconds / 86400, 1);
					$trackCount = $playlist->getTrackCount();

					if ($dryRun) {
						$log("[Dry Run] Would delete old Last.fm playlist '{$playlistName}' (ID {$playlistId}, {$trackCount} tracks, age {$ageDays} days)");
						$deletedPlaylists[] = [
							'id' => $playlistId,
							'name' => $playlistName,
							'tracks' => $trackCount,
							'age_days' => $ageDays,
						];
					} else {
						try {
							$playlistBusinessLayer->delete($playlistId, $userId);
							$log("Deleted old Last.fm playlist '{$playlistName}' (ID {$playlistId}, {$trackCount} tracks, age {$ageDays} days)");
							$deletedPlaylists[] = [
								'id' => $playlistId,
								'name' => $playlistName,
								'tracks' => $trackCount,
								'age_days' => $ageDays,
							];
						} catch (\Throwable $e) {
							$log("Failed to delete playlist '{$playlistName}' (ID {$playlistId}): " . $e->getMessage());
						}
					}
				}
			}
		}

		// Clean up orphaned config keys and undated station files
		if (!$dryRun) {
			try {
				$this->deleteFileFromListenBrainzFolder($userId, 'Recommendations.m3u');
				$this->deleteFileFromListenBrainzFolder($userId, 'Mix.m3u');
				$this->config->deleteUserValue($userId, 'music', 'lastfm.playlist.recommended');
				$this->config->deleteUserValue($userId, 'music', 'lastfm.playlist.mix');

				$userKeys = $this->config->getUserKeys($userId, 'music');
				foreach ($userKeys as $key) {
					if (\strpos($key, 'lastfm.playlist.recommended.') === 0 || \strpos($key, 'lastfm.playlist.mix.') === 0) {
						$plId = (int)$this->config->getUserValue($userId, 'music', $key, '0');
						if ($plId <= 0) {
							$this->config->deleteUserValue($userId, 'music', $key);
						} else {
							try {
								$playlistBusinessLayer->find($plId, $userId);
							} catch (\Throwable) {
								$this->config->deleteUserValue($userId, 'music', $key);
							}
						}
					}
				}
			} catch (\Throwable) {
			}
		}

		return ['deleted_playlists' => $deletedPlaylists];
	}

	/**
	 * Ensure the "Most Listened (Last.fm)" playlist for the user is limited to the top $limit tracks.
	 *
	 * @param string $userId Nextcloud user ID
	 * @param int $limit Max tracks (default 200)
	 * @return array{found: bool, playlist_id: ?int, previous_count: int, new_count: int}
	 */
	public function limitMostListenedPlaylist(string $userId, int $limit = 200) : array {
		$playlistBusinessLayer = $this->getPlaylistBusinessLayer();
		$configKey = 'lastfm.playlist.most_listened';
		$playlistId = (int)$this->config->getUserValue($userId, 'music', $configKey, '0');
		$playlist = null;

		if ($playlistId > 0) {
			try {
				$playlist = $playlistBusinessLayer->find($playlistId, $userId);
			} catch (\Throwable) {
				$playlist = null;
			}
		}

		// Fallback: search by name
		if (!$playlist) {
			$matching = $playlistBusinessLayer->findAllByName('Most Listened (Last.fm)', $userId);
			if (!empty($matching)) {
				$playlist = $matching[0];
				$playlistId = $playlist->getId();
				$this->config->setUserValue($userId, 'music', $configKey, (string)$playlistId);
			}
		}

		if (!$playlist) {
			return [
				'found' => false,
				'playlist_id' => null,
				'previous_count' => 0,
				'new_count' => 0,
			];
		}

		$currentTracks = $playlist->getTrackIdsAsArray();
		$previousCount = \count($currentTracks);

		if ($previousCount > $limit) {
			$trimmedTracks = \array_slice($currentTracks, 0, $limit);
			$playlistBusinessLayer->setTracks($trimmedTracks, $playlist->getId(), $userId);
			$newCount = \count($trimmedTracks);
			$this->logger->info("Trimmed 'Most Listened (Last.fm)' playlist (ID {$playlistId}) from {$previousCount} to {$newCount} tracks");
		} else {
			$newCount = $previousCount;
		}

		return [
			'found' => true,
			'playlist_id' => $playlistId,
			'previous_count' => $previousCount,
			'new_count' => $newCount,
		];
	}

	private function getApiKey() : ?string {
		if (!empty($this->apiKey)) {
			return $this->apiKey;
		}
		return $this->config->getSystemValue('music.lastfm_api_key', null);
	}

	private function getInfoFromLastFm(array $args) : array {
		$apiKey = $this->getApiKey();
		if (empty($apiKey)) {
			return ['api_key_set' => false];
		} else {
			// append the standard args
			$args['api_key'] = $apiKey;
			$args['format'] = 'json';

			// remove args with null or empty values
			$args = \array_filter($args, [StringUtil::class, 'isNonEmptyString']);

			// glue arg keys and values together ...
			$argStrings = \array_map(fn ($key, $value) => ($key . '=' . \urlencode($value)), \array_keys($args), $args);
			// ... and form the final query string
			$queryString = '?' . \implode('&', $argStrings);

			['content' => $info, 'status_code' => $statusCode, 'message' => $msg] = HttpUtil::loadFromUrl(self::LASTFM_URL . $queryString);

			if ($info === false) {
				// When an album is not found, Last.fm returns 404 but that is not a sign of broken connection.
				// Interestingly, not finding an artist is still responded with the code 200.
				$info = ['connection_ok' => ($statusCode === 404)];
			} else {
				$info = \json_decode($info, true);
				$info['connection_ok'] = true;
			}
			$info['status_code'] = $statusCode;
			$info['status_msg'] = $msg;
			$info['api_key_set'] = true;

			// Sometimes passing an MBID to Last.fm makes it unable to find the artist/album/track even if it is present in the library.
			// In such cases, we try again without the MBID.
			if (isset($args['mbid']) && ($statusCode === 404 || ($info['error'] === 6))) {
				$this->logger->info("Last.fm didn't find the item with MBID {$args['mbid']}. Retrying without MBID.");
				unset($args['mbid']);
				return $this->getInfoFromLastFm($args);
			} else {
				return $info;
			}
		}
	}

	private function errorResponse(string $message) : array {
		return [
			'api_key_set'   => !empty($this->apiKey),
			'connection_ok' => 'unknown',
			'status_code'   => -1,
			'status_msg'    => $message
		];
	}
}
