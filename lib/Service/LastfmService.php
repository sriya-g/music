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
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Db\Artist;
use OCA\Music\Db\MatchMode;
use OCA\Music\Db\Track;
use OCA\Music\Utility\HttpUtil;
use OCA\Music\Utility\StringUtil;
use OCP\IConfig;

class LastfmService {

	private const LASTFM_URL = 'http://ws.audioscrobbler.com/2.0/';

	private string $apiKey;

	public function __construct(
		private IConfig $config,
		private AlbumBusinessLayer $albumBusinessLayer,
		private ArtistBusinessLayer $artistBusinessLayer,
		private TrackBusinessLayer $trackBusinessLayer,
		private Logger $logger,
	) {
		$this->apiKey = (string)$config->getSystemValue('music.lastfm_api_key', '');
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
	 * Sync Last.fm listen counts (playcount) into Nextcloud Music for the user.
	 *
	 * @param string $userId Nextcloud user ID
	 * @param string|null $username Optional Last.fm username (falls back to connected Last.fm account)
	 * @param string $period overall | 7day | 1month | 3month | 6month | 12month
	 * @param int $maxTracks Maximum number of tracks to fetch from Last.fm
	 * @param bool $createPlaylist Whether to create/update a "Most Listened (Last.fm)" playlist
	 * @param bool $onlyIfGreater Update only if Last.fm play count is greater than current play count
	 * @param callable|null $logCallback Optional callback for progress logging fn(string $msg)
	 * @return array Result summary
	 */
	public function syncUserListenCounts(
		string $userId,
		?string $username = null,
		string $period = 'overall',
		int $maxTracks = 5000,
		bool $createPlaylist = false,
		bool $onlyIfGreater = true,
		?callable $logCallback = null
	) : array {
		$log = function(string $msg) use ($logCallback) {
			$this->logger->info("[Last.fm Sync] " . $msg);
			if ($logCallback !== null) {
				$logCallback($msg);
			}
		};

		if (empty($username)) {
			// Try getting username from config
			$username = $this->config->getUserValue($userId, 'music', 'lastfm.username');
		}

		if (empty($username)) {
			// Try getting from ExternalScrobbler
			$app = \OC::$server->query(\OCA\Music\AppInfo\Application::class);
			$scrobblers = $app->get('externalScrobblers');
			foreach ($scrobblers as $scrobbler) {
				if ($scrobbler instanceof Scrobbling\ExternalScrobbler && $scrobbler->getIdentifier() === 'lastfm') {
					$username = $scrobbler->getUsername($userId);
					break;
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

		$log("Starting listen counts sync for user '{$userId}' using Last.fm user '{$username}' (period: {$period})");

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

				// Find match in local library
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

		$playlistId = null;
		if ($createPlaylist && !empty($orderedMatchedTrackIds)) {
			try {
				$playlistBusinessLayer = \OC::$server->query(\OCA\Music\BusinessLayer\PlaylistBusinessLayer::class);
				$configKey = 'lastfm.playlist.most_listened';
				$existingPlaylistId = (int)$this->config->getUserValue($userId, 'music', $configKey, '0');
				$playlist = null;

				if ($existingPlaylistId > 0) {
					try {
						$playlist = $playlistBusinessLayer->find($existingPlaylistId, $userId);
					} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
						$playlist = null;
					}
				}

				if (!$playlist) {
					$playlist = $playlistBusinessLayer->create('Most Listened (Last.fm)', $userId);
					$this->config->setUserValue($userId, 'music', $configKey, (string)$playlist->getId());
				}

				$playlistBusinessLayer->setTracks($orderedMatchedTrackIds, $playlist->getId(), $userId);
				$playlistId = $playlist->getId();
				$log("Created/updated playlist 'Most Listened (Last.fm)' (ID: {$playlistId}) with {$matchedCount} tracks");
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
			'playlist_id' => $playlistId,
			'message' => "Successfully synced listen counts: {$updatedCount} updated ({$matchedCount} matched from {$totalFetched} Last.fm tracks)."
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
