<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\Service\Discord;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\Db\Cache;

class SpotifyLinkResolver {

	/** @var array<string, array{trackUrl: string, artistUrl: string}> In-memory cache */
	private array $memoryCache = [];

	public function __construct(
		private Cache $cache,
		private ?Logger $logger = null,
	) {
	}

	/**
	 * @return array{trackUrl: string, artistUrl: string}
	 */
	public function resolveSpotifyUrls(
		string $title,
		string $artist,
		string $album,
		?string $mbRecordingId,
		string $userId
	): array {
		$cacheKey = 'spotify.url.' . \hash('fnv1a64', \strtolower($artist . "\0" . $title . "\0" . $album));
		if (isset($this->memoryCache[$cacheKey])) {
			return $this->memoryCache[$cacheKey];
		}

		$cached = $this->cache->get($userId, $cacheKey);
		if ($cached !== null) {
			$data = \json_decode($cached, true);
			if (\is_array($data) && isset($data['trackUrl'], $data['artistUrl'])) {
				$this->memoryCache[$cacheKey] = $data;
				return $data;
			}
		}

		$trackUrl = null;
		$artistUrl = 'https://open.spotify.com/search/' . \rawurlencode(\trim($artist));

		// 1. Try MBID lookup via ListenBrainz Labs
		if (!empty($mbRecordingId)) {
			$spotifyId = $this->lookupByMbid($mbRecordingId);
			if ($spotifyId !== null) {
				$trackUrl = "https://open.spotify.com/track/{$spotifyId}";
			}
		}

		// 2. Try metadata lookup
		if ($trackUrl === null && !empty($title) && !empty($artist)) {
			$spotifyId = $this->lookupByMetadata($artist, $title, $album);
			if ($spotifyId !== null) {
				$trackUrl = "https://open.spotify.com/track/{$spotifyId}";
			}
		}

		// 3. Fallback to Spotify search
		if ($trackUrl === null) {
			$trackUrl = 'https://open.spotify.com/search/' . \rawurlencode(\trim("{$artist} {$title}"));
		}

		$result = [
			'trackUrl' => $trackUrl,
			'artistUrl' => $artistUrl,
		];

		$this->memoryCache[$cacheKey] = $result;
		try {
			$this->cache->set($userId, $cacheKey, \json_encode($result));
		} catch (\Throwable $e) {
			// ignore caching errors
		}

		return $result;
	}

	private function lookupByMbid(string $mbid): ?string {
		$payload = \json_encode([['recording_mbid' => $mbid]]);
		return $this->callListenBrainzLabs('https://labs.api.listenbrainz.org/spotify-id-from-mbid/json', $payload);
	}

	private function lookupByMetadata(string $artist, string $title, string $album): ?string {
		$payload = \json_encode([[
			'artist_name' => $artist,
			'track_name' => $title,
			'release_name' => $album,
		]]);
		return $this->callListenBrainzLabs('https://labs.api.listenbrainz.org/spotify-id-from-metadata/json', $payload);
	}

	private function callListenBrainzLabs(string $url, string $jsonPayload): ?string {
		$ch = \curl_init($url);
		\curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $jsonPayload,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'User-Agent: NextcloudMusic/1.0',
			],
			CURLOPT_TIMEOUT => 4,
		]);

		$resp = \curl_exec($ch);
		$code = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
		\curl_close($ch);

		if ($code >= 200 && $code < 300 && \is_string($resp)) {
			$results = \json_decode($resp, true);
			if (\is_array($results)) {
				foreach ($results as $item) {
					if (!empty($item['spotify_track_ids']) && \is_array($item['spotify_track_ids'])) {
						foreach ($item['spotify_track_ids'] as $id) {
							if (\is_string($id) && $this->isValidSpotifyId($id)) {
								return $id;
							}
						}
					}
				}
			}
		}

		return null;
	}

	private function isValidSpotifyId(string $id): bool {
		return \preg_match('/^[a-zA-Z0-9]{22}$/', $id) === 1;
	}
}
