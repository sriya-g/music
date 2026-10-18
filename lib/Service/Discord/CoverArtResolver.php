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
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\Db\Cache;
use OCA\Music\Db\Track;

class CoverArtResolver {

	public const DEFAULT_FALLBACK_IMAGE = 'https://raw.githubusercontent.com/nextcloud/server/master/core/img/logo/logo.png';

	/** @var array<string, string> In-memory cache for fast lookups */
	private array $memoryCache = [];

	public function __construct(
		private AlbumBusinessLayer $albumBusinessLayer,
		private Cache $cache,
		private ?Logger $logger = null,
	) {
	}

	public function resolveCoverArtUrl(Track $track, string $userId, bool $caaEnabled = true): string {
		$album = null;
		$albumId = $track->getAlbumId();
		if ($albumId > 0) {
			try {
				$album = $this->albumBusinessLayer->find($albumId, $userId);
			} catch (\Throwable $e) {
				$album = null;
			}
		}

		// 1. Cover Art Archive
		if ($caaEnabled && $album !== null) {
			$mbReleaseId = $album->getMbid();
			$mbReleaseGroupId = $album->getMbidGroup();

			$caaUrl = $this->getImageViaCoverArt($mbReleaseId, $mbReleaseGroupId, $userId);
			if (!empty($caaUrl)) {
				return $caaUrl;
			}
		}

		// 2. Fallback
		return self::DEFAULT_FALLBACK_IMAGE;
	}

	private function getImageViaCoverArt(?string $mbReleaseId, ?string $mbReleaseGroupId, string $userId): ?string {
		if (empty($mbReleaseId) && empty($mbReleaseGroupId)) {
			return null;
		}

		$cacheKey = !empty($mbReleaseId) ? 'caa.artwork.' . $mbReleaseId : 'caa.artwork.rg.' . $mbReleaseGroupId;
		if (isset($this->memoryCache[$cacheKey])) {
			return $this->memoryCache[$cacheKey] ?: null;
		}

		$cached = $this->cache->get($userId, $cacheKey);
		if ($cached !== null) {
			$this->memoryCache[$cacheKey] = $cached;
			return $cached ?: null;
		}

		$url = null;
		if (!empty($mbReleaseId)) {
			$url = $this->headCoverArt("https://coverartarchive.org/release/{$mbReleaseId}/front-500");
		}

		if (empty($url) && !empty($mbReleaseGroupId)) {
			$url = $this->headCoverArt("https://coverartarchive.org/release-group/{$mbReleaseGroupId}/front-500");
		}

		$valueToStore = $url ?? '';
		$this->memoryCache[$cacheKey] = $valueToStore;
		try {
			$this->cache->set($userId, $cacheKey, $valueToStore);
		} catch (\Throwable $e) {
			// ignore caching errors
		}

		return $url;
	}

	private function headCoverArt(string $url): ?string {
		$ch = \curl_init($url);
		\curl_setopt_array($ch, [
			CURLOPT_NOBODY => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HEADER => true,
			CURLOPT_TIMEOUT => 4,
			CURLOPT_USERAGENT => 'NextcloudMusic/1.0',
		]);

		$response = \curl_exec($ch);
		$httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
		\curl_close($ch);

		if ($httpCode === 307 && \is_string($response)) {
			if (\preg_match('/^Location:\s*(https?:\/\/[^\r\n]+)/mi', $response, $matches)) {
				return \trim($matches[1]);
			}
		}

		return null;
	}
}
