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
use OCA\Music\BusinessLayer\ArtistBusinessLayer;
use OCA\Music\Db\Cache;
use OCA\Music\Db\Track;
use OCP\IL10N;

class DiscordPresenceService {

	/** @var array<string, array{client: DiscordGatewayClient, heartbeatInterval: int, nextHeartbeat: float, clientId: string, token: string, currentTrackId: ?int}> */
	private array $sessions = [];

	/** @var array<string, string> */
	private array $assetCache = [];

	public function __construct(
		private CoverArtResolver $coverArtResolver,
		private SpotifyLinkResolver $spotifyLinkResolver,
		private AlbumBusinessLayer $albumBusinessLayer,
		private ArtistBusinessLayer $artistBusinessLayer,
		private Cache $cache,
		private IL10N $l10n,
		private ?Logger $logger = null,
	) {
	}

	public function isUserConnected(string $userId): bool {
		return isset($this->sessions[$userId]) && $this->sessions[$userId]['client']->isConnected();
	}

	public function connectUser(string $userId, string $clientId, string $token): bool {
		if ($this->isUserConnected($userId)) {
			return true;
		}

		$client = new DiscordGatewayClient($this->logger);
		if (!$client->connect()) {
			$this->logger?->warning("Could not connect to Discord Gateway for user {$userId}");
			return false;
		}

		// Wait briefly for Op 10 Hello from Discord
		$heartbeatInterval = 41250;
		$startTime = \microtime(true);
		while (\microtime(true) - $startTime < 4.0) {
			$messages = $client->readMessages();
			foreach ($messages as $msg) {
				if (isset($msg['op']) && $msg['op'] === 10 && isset($msg['d']['heartbeat_interval'])) {
					$heartbeatInterval = (int)$msg['d']['heartbeat_interval'];
					break 2;
				}
			}
			\usleep(50000); // 50ms
		}

		// Send Op 2 Identify
		$identifyPayload = [
			'token' => $token,
			'intents' => 0,
			'properties' => [
				'os' => 'linux',
				'browser' => 'Nextcloud Music',
				'device' => 'Nextcloud Music',
			],
		];

		if (!$client->sendOp(2, $identifyPayload)) {
			$this->logger?->warning("Failed to send identify payload to Discord for user {$userId}");
			$client->disconnect();
			return false;
		}

		$nextHeartbeat = \microtime(true) + ($heartbeatInterval / 1000.0) * (\random_int(1, 9) / 10.0);

		$this->sessions[$userId] = [
			'client' => $client,
			'heartbeatInterval' => $heartbeatInterval,
			'nextHeartbeat' => $nextHeartbeat,
			'clientId' => $clientId,
			'token' => $token,
			'currentTrackId' => null,
		];

		$this->logger?->info("Discord Gateway session established for user {$userId}");
		return true;
	}

	public function disconnectUser(string $userId): void {
		if (isset($this->sessions[$userId])) {
			$this->clearPresence($userId);
			$this->sessions[$userId]['client']->disconnect();
			unset($this->sessions[$userId]);
			$this->logger?->info("Discord Gateway disconnected for user {$userId}");
		}
	}

	public function disconnectAll(): void {
		foreach (\array_keys($this->sessions) as $userId) {
			$this->disconnectUser((string)$userId);
		}
	}

	public function updatePresence(
		string $userId,
		Track $track,
		int $timeOfPlay,
		array $config
	): bool {
		$clientId = $config['clientId'] ?? '';
		$token = $config['userToken'] ?? '';
		if (empty($clientId) || empty($token)) {
			return false;
		}

		if (!$this->isUserConnected($userId)) {
			if (!$this->connectUser($userId, $clientId, $token)) {
				return false;
			}
		}

		$session = &$this->sessions[$userId];
		$client = $session['client'];

		// Resolve Artist & Album names
		$artistName = '';
		$artistId = $track->getArtistId();
		if ($artistId > 0) {
			try {
				$artistObj = $this->artistBusinessLayer->find($artistId, $userId);
				$artistName = $artistObj->getNameString($this->l10n);
			} catch (\Throwable $e) {
				$artistName = '';
			}
		}

		$albumName = '';
		$albumId = $track->getAlbumId();
		if ($albumId > 0) {
			try {
				$albumObj = $this->albumBusinessLayer->find($albumId, $userId);
				$albumName = $albumObj->getName();
			} catch (\Throwable $e) {
				$albumName = '';
			}
		}

		// Resolve Activity Name Display
		$activityMode = $config['activityName'] ?? 'Default';
		$activityName = match ($activityMode) {
			'Track' => $track->getTitle(),
			'Artist' => !empty($artistName) ? $artistName : 'Nextcloud Music',
			'Album' => !empty($albumName) ? $albumName : 'Nextcloud Music',
			'Custom' => !empty($config['customActivityName']) ? $config['customActivityName'] : 'Nextcloud Music',
			default => 'Nextcloud Music',
		};

		// Spotify Links
		$spotifyUrls = ['trackUrl' => '', 'artistUrl' => ''];
		if (!empty($config['spotifyLinks'])) {
			$spotifyUrls = $this->spotifyLinkResolver->resolveSpotifyUrls(
				$track->getTitle(),
				$artistName,
				$albumName,
				$track->getMbid(),
				$userId
			);
		}

		// Cover Art URL
		$caaEnabled = !isset($config['caaEnabled']) || (bool)$config['caaEnabled'];
		$coverArtUrl = $this->coverArtResolver->resolveCoverArtUrl($track, $userId, $caaEnabled);

		// Process external asset with Discord
		$largeImage = $this->processExternalAsset($coverArtUrl, $clientId, $token, $userId);

		$duration = $track->getLength();
		if ($duration <= 0) {
			$duration = 180; // fallback 3 minutes
		}

		$startMs = $timeOfPlay * 1000;
		$endMs = ($timeOfPlay + $duration) * 1000;

		$activity = [
			'application_id' => $clientId,
			'name' => $this->truncateText($activityName, 128),
			'type' => 2, // Listening to
			'details' => $this->truncateText($track->getTitle(), 128),
			'state' => $this->truncateText($artistName, 128),
			'status_display_type' => 0,
			'timestamps' => [
				'start' => $startMs,
				'end' => $endMs,
			],
			'assets' => [
				'large_image' => $largeImage,
				'large_text' => $this->truncateText($albumName, 128),
			],
		];

		if (!empty($spotifyUrls['trackUrl'])) {
			$activity['details_url'] = $this->truncateUrl($spotifyUrls['trackUrl']);
			$activity['assets']['large_url'] = $this->truncateUrl($spotifyUrls['trackUrl']);
		}
		if (!empty($spotifyUrls['artistUrl'])) {
			$activity['state_url'] = $this->truncateUrl($spotifyUrls['artistUrl']);
		}

		$payload = [
			'since' => $startMs,
			'activities' => [$activity],
			'status' => 'online',
			'afk' => false,
		];

		$success = $client->sendOp(3, $payload);
		if ($success) {
			$session['currentTrackId'] = $track->getId();
			$this->logger?->info("Updated Discord presence for user {$userId}: {$track->getTitle()} by {$artistName}");
		}

		return $success;
	}

	public function clearPresence(string $userId): bool {
		if (!$this->isUserConnected($userId)) {
			return false;
		}

		$client = $this->sessions[$userId]['client'];
		$success = $client->sendOp(3, [
			'since' => null,
			'activities' => [],
			'status' => 'online',
			'afk' => false,
		]);

		$this->sessions[$userId]['currentTrackId'] = null;
		$this->logger?->info("Cleared Discord presence for user {$userId}");
		return $success;
	}

	public function tickUser(string $userId): void {
		if (!isset($this->sessions[$userId])) {
			return;
		}

		$session = &$this->sessions[$userId];
		$client = $session['client'];

		if (!$client->isConnected()) {
			$this->logger?->warning("Discord connection lost for user {$userId}, attempting reconnect...");
			$this->disconnectUser($userId);
			return;
		}

		// Read incoming messages
		$messages = $client->readMessages();
		foreach ($messages as $msg) {
			$op = $msg['op'] ?? null;
			if ($op === 1) {
				// Discord requested immediate heartbeat
				$client->sendOp(1, $client->getLastSequence());
			} elseif ($op === 7) {
				// Reconnect request
				$this->logger?->info("Discord requested reconnect for user {$userId}");
				$this->disconnectUser($userId);
				return;
			} elseif ($op === 9) {
				// Invalid session
				$this->logger?->warning("Discord session invalidated for user {$userId}");
				$this->disconnectUser($userId);
				return;
			}
		}

		// Check heartbeat timer
		$now = \microtime(true);
		if ($now >= $session['nextHeartbeat']) {
			$client->sendOp(1, $client->getLastSequence());
			$session['nextHeartbeat'] = $now + ($session['heartbeatInterval'] / 1000.0);
		}
	}

	public function getCurrentTrackId(string $userId): ?int {
		return $this->sessions[$userId]['currentTrackId'] ?? null;
	}

	private function processExternalAsset(string $imageUrl, string $clientId, string $token, string $userId): string {
		if (empty($imageUrl)) {
			return '';
		}

		if (\str_starts_with($imageUrl, 'mp:')) {
			return $imageUrl;
		}

		$cacheKey = 'discord.asset.' . \hash('fnv1a64', $imageUrl);
		if (isset($this->assetCache[$cacheKey])) {
			return $this->assetCache[$cacheKey];
		}

		$cached = $this->cache->get($userId, $cacheKey);
		if (!empty($cached)) {
			$this->assetCache[$cacheKey] = $cached;
			return $cached;
		}

		$body = \json_encode(['urls' => [$imageUrl]]);
		$ch = \curl_init("https://discord.com/api/v9/applications/{$clientId}/external-assets");
		\curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				"Authorization: {$token}",
				'Content-Type: application/json',
				'User-Agent: NextcloudMusic/1.0',
			],
			CURLOPT_TIMEOUT => 5,
		]);

		$resp = \curl_exec($ch);
		$code = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
		\curl_close($ch);

		if ($code >= 200 && $code < 300 && \is_string($resp)) {
			$data = \json_decode($resp, true);
			if (\is_array($data) && !empty($data[0]['external_asset_path'])) {
				$processed = 'mp:' . $data[0]['external_asset_path'];
				$this->assetCache[$cacheKey] = $processed;
				try {
					$this->cache->set($userId, $cacheKey, $processed);
				} catch (\Throwable $e) {
				}
				return $processed;
			}
		}

		return $imageUrl;
	}

	private function truncateText(string $text, int $maxLength): string {
		if (\mb_strlen($text) <= $maxLength) {
			return $text;
		}
		return \mb_substr($text, 0, $maxLength - 1) . '…';
	}

	private function truncateUrl(string $url): string {
		if (\strlen($url) <= 256) {
			return $url;
		}
		return '';
	}
}
