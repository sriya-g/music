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

use DateTime;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\BusinessLayer\PlaylistBusinessLayer;
use OCA\Music\Db\Track;
use OCA\Music\Utility\StringUtil;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;

class ListenBrainzScrobbler extends ExternalScrobbler {

	public function __construct(
		private IConfig $config,
		private Logger $logger,
		private IURLGenerator $urlGenerator,
		private AlbumBusinessLayer $albumBusinessLayer,
		private ICrypto $crypto,
		private string $appName
	) {
		parent::__construct(
			$config,
			$logger,
			$urlGenerator,
			$albumBusinessLayer,
			$crypto,
			'ListenBrainz',
			'listenbrainz',
			'https://api.listenbrainz.org/1/submit-listens',
			'',
			$appName
		);
	}

	/**
	 * @throws ScrobbleServiceException when validation fails
	 */
	public function generateSession(string $token, string $userId) : void {
		if (empty($token)) {
			throw new ScrobbleServiceException('Token cannot be empty', 0);
		}
		if (!$this->validateToken($token)) {
			throw new ScrobbleServiceException('Invalid ListenBrainz user token', 401);
		}
		$this->saveApiSession($userId, $token);
	}

	private function validateToken(string $token) : bool {
		$ch = \curl_init();
		if (!$ch) {
			$this->logger->error('Failed to initialize a curl handle, is the php curl extension installed?');
			throw new \RuntimeException('Unable to initialize a curl handle');
		}
		\curl_setopt($ch, \CURLOPT_URL, 'https://api.listenbrainz.org/1/validate-token');
		\curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, 10);
		\curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($ch, \CURLOPT_HTTPHEADER, [
			"Authorization: Token {$token}",
			"User-Agent: NextcloudMusicApp/1.0"
		]);
		$responseString = \curl_exec($ch);
		$httpCode = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
		\curl_close($ch);

		if ($responseString === false || $httpCode !== 200) {
			return false;
		}

		$data = \json_decode((string)$responseString, true);
		return isset($data['valid']) && $data['valid'] === true;
	}

	public function recordTrackPlayed(Track $track, ?DateTime $timeOfPlay = null) : void {
		$timeOfPlay = $timeOfPlay ?? new DateTime();
		$userId = $track->getUserId();
		$sessionKey = $this->getApiSession($userId);
		if (!$sessionKey) {
			$this->logger->warning("ListenBrainz scrobble skipped: No session key found for user {$userId}");
			return;
		}

		if (empty($track->getArtistName())) {
			$this->logger->warning("Skip scrobbling track {$track->getId()} '{$track->getTitle()}' with unknown artist to ListenBrainz");
			return;
		}

		if ($track->getLength() <= 30) {
			$this->logger->warning("Track '{$track->getTitle()}' by '{$track->getArtistName()}' is too short ({$track->getLength()}s) to scrobble to ListenBrainz");
			return;
		}

		$this->albumBusinessLayer->injectAlbumsToTracks([$track], $userId);

		$trackMetadata = [
			'artist_name' => $track->getArtistName(),
			'track_name' => $track->getTitle(),
		];

		if (!empty($track->getAlbumName())) {
			$trackMetadata['release_name'] = $track->getAlbumName();
		}

		$additionalInfo = [];
		if (!empty($track->getNumber())) {
			$additionalInfo['tracknumber'] = $track->getNumber();
		}
		$albumArtistName = $track->getAlbum()?->getAlbumArtistName();
		if (!empty($albumArtistName) && $albumArtistName !== $track->getArtistName()) {
			$additionalInfo['albumartist'] = $albumArtistName;
		}
		if ($track->getLength() > 0) {
			$additionalInfo['duration'] = $track->getLength();
		}
		$additionalInfo['submission_client'] = 'Nextcloud Music';
		$additionalInfo['submission_client_version'] = \OCA\Music\Utility\AppInfo::getVersion();

		if (!empty($additionalInfo)) {
			$trackMetadata['additional_info'] = $additionalInfo;
		}

		$payloadData = [
			'listen_type' => 'single',
			'payload' => [
				[
					'listened_at' => $timeOfPlay->getTimestamp(),
					'track_metadata' => $trackMetadata
				]
			]
		];

		$this->submitListen($sessionKey, $payloadData);
	}

	public function setNowPlaying(Track $track, ?DateTime $timeOfPlay = null) : void {
		$userId = $track->getUserId();
		$sessionKey = $this->getApiSession($userId);
		if (!$sessionKey) {
			return;
		}

		if (empty($track->getArtistName())) {
			$this->logger->info("Skip setting now playing track {$track->getId()} '{$track->getTitle()}' with unknown artist to ListenBrainz");
			return;
		}

		$this->albumBusinessLayer->injectAlbumsToTracks([$track], $userId);

		$trackMetadata = [
			'artist_name' => $track->getArtistName(),
			'track_name' => $track->getTitle(),
		];

		if (!empty($track->getAlbumName())) {
			$trackMetadata['release_name'] = $track->getAlbumName();
		}

		$additionalInfo = [];
		if (!empty($track->getNumber())) {
			$additionalInfo['tracknumber'] = $track->getNumber();
		}
		$albumArtistName = $track->getAlbum()?->getAlbumArtistName();
		if (!empty($albumArtistName) && $albumArtistName !== $track->getArtistName()) {
			$additionalInfo['albumartist'] = $albumArtistName;
		}
		if ($track->getLength() > 0) {
			$additionalInfo['duration'] = $track->getLength();
		}
		$additionalInfo['submission_client'] = 'Nextcloud Music';
		$additionalInfo['submission_client_version'] = \OCA\Music\Utility\AppInfo::getVersion();

		if (!empty($additionalInfo)) {
			$trackMetadata['additional_info'] = $additionalInfo;
		}

		$payloadData = [
			'listen_type' => 'playing_now',
			'payload' => [
				[
					'track_metadata' => $trackMetadata
				]
			]
		];

		$this->submitListen($sessionKey, $payloadData);
	}

	private function submitListen(string $sessionKey, array $payloadData) : void {
		$ch = \curl_init();
		if (!$ch) {
			$this->logger->error('Failed to initialize a curl handle, is the php curl extension installed?');
			return;
		}
		\curl_setopt($ch, \CURLOPT_URL, 'https://api.listenbrainz.org/1/submit-listens');
		\curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, 10);
		\curl_setopt($ch, \CURLOPT_POST, true);
		\curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($ch, \CURLOPT_HTTPHEADER, [
			"Authorization: Token {$sessionKey}",
			"Content-Type: application/json",
			"User-Agent: NextcloudMusicApp/1.0"
		]);
		\curl_setopt($ch, \CURLOPT_POSTFIELDS, \json_encode($payloadData));
		$responseString = \curl_exec($ch);
		$httpCode = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
		$curlError = \curl_error($ch);
		\curl_close($ch);

		if ($responseString === false || $httpCode !== 200) {
			$this->logger->warning("Failed to submit listen to ListenBrainz. HTTP Code: {$httpCode}, cURL Error: {$curlError}, Response: " . (string)$responseString);
		} else {
			$this->logger->warning("Successfully submitted listen to ListenBrainz. Response: " . (string)$responseString);
		}
	}

	public function syncPlaylists(string $userId) : void {
		$sessionKey = $this->getApiSession($userId);
		if (!$sessionKey) {
			$this->logger->warning("ListenBrainz playlist sync: no session key for user {$userId}");
			return;
		}

		$this->logger->warning("ListenBrainz playlist sync started for user {$userId}");

		// Validate token to get username
		$ch = \curl_init();
		if (!$ch) {
			$this->logger->error('Failed to initialize a curl handle');
			return;
		}
		\curl_setopt($ch, \CURLOPT_URL, 'https://api.listenbrainz.org/1/validate-token');
		\curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, 10);
		\curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($ch, \CURLOPT_HTTPHEADER, [
			"Authorization: Token {$sessionKey}",
			"User-Agent: NextcloudMusicApp/1.0"
		]);
		$responseString = \curl_exec($ch);
		$httpCode = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
		\curl_close($ch);

		if ($responseString === false || $httpCode !== 200) {
			$this->logger->warning("ListenBrainz playlist sync failed for user {$userId}: token validation failed (HTTP {$httpCode})");
			return;
		}

		$tokenData = \json_decode((string)$responseString, true);
		if (!isset($tokenData['valid']) || $tokenData['valid'] !== true || !isset($tokenData['user_name'])) {
			$this->logger->warning("ListenBrainz playlist sync failed for user {$userId}: token invalid or username not returned");
			return;
		}

		$username = $tokenData['user_name'];
		$this->logger->warning("ListenBrainz playlist sync: validated username is '{$username}'");

		// Fetch playlists metadata from all endpoints: created, createdfor, collaborator
		$endpoints = [
			"https://api.listenbrainz.org/1/user/{$username}/playlists",
			"https://api.listenbrainz.org/1/user/{$username}/playlists/createdfor",
			"https://api.listenbrainz.org/1/user/{$username}/playlists/collaborator"
		];

		$allPlaylists = [];
		foreach ($endpoints as $endpointUrl) {
			$ch = \curl_init();
			\curl_setopt($ch, \CURLOPT_URL, $endpointUrl);
			\curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, 10);
			\curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
			\curl_setopt($ch, \CURLOPT_HTTPHEADER, [
				"Authorization: Token {$sessionKey}",
				"User-Agent: NextcloudMusicApp/1.0"
			]);
			$responseString = \curl_exec($ch);
			$httpCode = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
			\curl_close($ch);

			if ($responseString === false || $httpCode !== 200) {
				$this->logger->warning("ListenBrainz playlist sync: failed to fetch from {$endpointUrl} (HTTP {$httpCode})");
				continue;
			}

			$playlistsData = \json_decode((string)$responseString, true);
			if (isset($playlistsData['playlists']) && \is_array($playlistsData['playlists'])) {
				foreach ($playlistsData['playlists'] as $lbPlaylist) {
					if (!isset($lbPlaylist['playlist'])) {
						continue;
					}
					$identifier = $lbPlaylist['playlist']['identifier'] ?? '';
					if (empty($identifier)) {
						continue;
					}
					// Parse UUID
					$mbid = '';
					if (\preg_match('/\/playlist\/([a-f0-9\-]+)/i', $identifier, $matches)) {
						$mbid = $matches[1];
					}
					if (!empty($mbid)) {
						$allPlaylists[$mbid] = $lbPlaylist;
					}
				}
			}
		}

		$playlistsCount = \count($allPlaylists);
		$this->logger->warning("ListenBrainz playlist sync: found {$playlistsCount} unique playlists in total for user '{$username}'");

		$playlistBusinessLayer = \OC::$server->query(PlaylistBusinessLayer::class);
		$trackBusinessLayer = \OC::$server->query(\OCA\Music\BusinessLayer\TrackBusinessLayer::class);

		foreach ($allPlaylists as $mbid => $lbPlaylist) {
			$title = $lbPlaylist['playlist']['title'];
			$this->logger->warning("ListenBrainz playlist sync: fetching details for playlist '{$title}' ({$mbid})");

			// Fetch the full playlist details including tracks
			$ch = \curl_init();
			\curl_setopt($ch, \CURLOPT_URL, "https://api.listenbrainz.org/1/playlist/{$mbid}");
			\curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, 10);
			\curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
			\curl_setopt($ch, \CURLOPT_HTTPHEADER, [
				"Authorization: Token {$sessionKey}",
				"User-Agent: NextcloudMusicApp/1.0"
			]);
			$responseString = \curl_exec($ch);
			$httpCode = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
			\curl_close($ch);

			if ($responseString === false || $httpCode !== 200) {
				$this->logger->warning("ListenBrainz playlist sync: failed to fetch details for playlist {$mbid}");
				continue;
			}

			$playlistDetails = \json_decode((string)$responseString, true);
			if (!isset($playlistDetails['playlist']) || !isset($playlistDetails['playlist']['track'])) {
				$this->logger->warning("ListenBrainz playlist sync: details for playlist {$mbid} contains no tracks list");
				continue;
			}

			// Map ListenBrainz track list to Nextcloud track IDs
			$ncTrackIds = [];
			foreach ($playlistDetails['playlist']['track'] as $lbTrack) {
				$trackTitle = $lbTrack['title'] ?? '';
				$artistName = $lbTrack['creator'] ?? '';
				if (empty($trackTitle) || empty($artistName)) {
					continue;
				}
				// Find matching local track
				$foundTracks = $trackBusinessLayer->findAllByNameArtistOrAlbum($trackTitle, $artistName, null, $userId);
				if (!empty($foundTracks)) {
					$ncTrackIds[] = $foundTracks[0]->getId();
				}
			}

			$tracksMatchedCount = \count($ncTrackIds);
			$totalTracksCount = \count($playlistDetails['playlist']['track']);
			$this->logger->warning("ListenBrainz playlist sync: playlist '{$title}' has {$totalTracksCount} tracks, matched {$tracksMatchedCount} in local database");

			// Sync with Nextcloud playlist
			$configKey = "listenbrainz.playlist.{$mbid}";
			$playlistId = (int)$this->config->getUserValue($userId, 'music', $configKey, '0');
			
			$ncPlaylist = null;
			if ($playlistId > 0) {
				try {
					$ncPlaylist = $playlistBusinessLayer->find($playlistId, $userId);
				} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
					$ncPlaylist = null;
				}
			}

			if (!$ncPlaylist) {
				// Create new playlist
				$playlistName = $title . " (ListenBrainz)";
				$ncPlaylist = $playlistBusinessLayer->create($playlistName, $userId);
				$this->config->setUserValue($userId, 'music', $configKey, (string)$ncPlaylist->getId());
				$this->logger->warning("ListenBrainz playlist sync: created Nextcloud playlist '{$playlistName}' (ID {$ncPlaylist->getId()})");
			} else {
				// Update name if needed
				$playlistName = $title . " (ListenBrainz)";
				if ($ncPlaylist->getName() !== $playlistName) {
					$playlistBusinessLayer->rename($playlistName, $ncPlaylist->getId(), $userId);
				}
				$this->logger->warning("ListenBrainz playlist sync: updating existing Nextcloud playlist '{$playlistName}' (ID {$ncPlaylist->getId()})");
			}

			// Set tracks
			$playlistBusinessLayer->setTracks($ncTrackIds, $ncPlaylist->getId(), $userId);
		}

		// Clean up old daily/weekly playlists
		$keys = $this->config->getUserKeys($userId, 'music');
		foreach ($keys as $key) {
			if (\strpos($key, 'listenbrainz.playlist.') === 0) {
				$playlistId = (int)$this->config->getUserValue($userId, 'music', $key, '0');
				if ($playlistId <= 0) {
					$this->config->deleteUserValue($userId, 'music', $key);
					continue;
				}

				try {
					$ncPlaylist = $playlistBusinessLayer->find($playlistId, $userId);
				} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
					$this->config->deleteUserValue($userId, 'music', $key);
					continue;
				}

				$playlistName = $ncPlaylist->getName() ?? '';
				
				// Check if it is a daily/weekly playlist
				$isDailyOrWeekly = false;
				if (\strpos($playlistName, '(ListenBrainz)') !== false) {
					if (\strpos($playlistName, 'Daily Jams') !== false ||
						\strpos($playlistName, 'Weekly Jams') !== false ||
						\strpos($playlistName, 'Weekly Exploration') !== false) {
						$isDailyOrWeekly = true;
					}
				}

				if ($isDailyOrWeekly) {
					$playlistDate = null;
					// Try to parse the date from the title (e.g. 2026-07-06)
					if (\preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $playlistName, $matches)) {
						$playlistDate = \strtotime($matches[1]);
					} else {
						// Fallback to creation date
						$createdStr = $ncPlaylist->getCreated();
						if ($createdStr) {
							if ($createdStr instanceof \DateTimeInterface) {
								$playlistDate = $createdStr->getTimestamp();
							} else {
								$playlistDate = \strtotime((string)$createdStr);
							}
						}
					}

					if ($playlistDate !== null && $playlistDate > 0) {
						$ageSeconds = \time() - $playlistDate;
						if ($ageSeconds > 7 * 86400) {
							// More than 7 days old, delete it!
							try {
								$playlistBusinessLayer->delete($playlistId, $userId);
								$this->logger->warning("ListenBrainz playlist sync: deleted old daily/weekly playlist '{$playlistName}' (ID {$playlistId})");
							} catch (\Throwable $e) {
								$this->logger->warning("ListenBrainz playlist sync: failed to delete old playlist '{$playlistName}': " . $e->getMessage());
							}
							$this->config->deleteUserValue($userId, 'music', $key);
						}
					}
				}
			}
		}

		$this->logger->warning("ListenBrainz playlist sync completed for user {$userId}");
	}
}
