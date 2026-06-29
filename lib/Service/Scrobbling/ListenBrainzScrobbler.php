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
use OCA\Music\Db\Track;
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
			return;
		}

		if (empty($track->getArtistName())) {
			$this->logger->info("Skip scrobbling track {$track->getId()} '{$track->getTitle()}' with unknown artist to ListenBrainz");
			return;
		}

		if ($track->getLength() <= 30) {
			$this->logger->info("Track '{$track->getTitle()}' by '{$track->getArtistName()}' is too short to scrobble to ListenBrainz");
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
		\curl_close($ch);

		if ($responseString === false || $httpCode !== 200) {
			$this->logger->warning("Failed to submit listen to ListenBrainz. HTTP Code: {$httpCode}, Response: " . (string)$responseString);
		}
	}
}
