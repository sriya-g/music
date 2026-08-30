<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2024 - 2026
 */

namespace OCA\Music\Service;

use OCP\IConfig;

/**
 * Service creating signature tokens for given URLs. This can be used to prove that an URL passed
 * by the client has been previously created by this back-end and the client is not trying to trick
 * the back-end to relay any other HTTP traffic. If we would allow making calls to just any URL,
 * then that would undermine the purpose of having the Content-Security-Policy in place.
 */
class StreamTokenService {

	private ?string $secret = null; // lazy load
	private const PRIVATE_SECRET_KEY = 'radio_stream_secret';

	public function __construct(
		private IConfig $config,
	) {
	}

	public function tokenForUrl(string $url) : string {
		$secret = $this->getPrivateSecret();
		return self::createToken($url, $secret);
	}

	public function urlTokenIsValid(string $url, string $token) : bool {
		$secret = $this->getPrivateSecret();
		return self::tokenIsValid($token, $url, $secret);
	}

	private static function createToken(string $message, string $privateSecret) : string {
		$salt = \random_bytes(32);
		$hash = \hash('sha256', $salt . $message . $privateSecret, /*binary=*/true);
		return \base64_encode($salt . $hash);
	}

	private static function tokenIsValid(string $token, string $message, string $privateSecret) : bool {
		$binToken = (string)\base64_decode($token);
		$salt = \substr($binToken, 0, 32);
		$validBinToken = $salt . \hash('sha256', $salt . $message . $privateSecret, /*binary=*/true);
		return ($binToken === $validBinToken);
	}

	private function getPrivateSecret() : string {
		// Load the secret all the way from the DB only once per request and cache it for later
		// invocations to avoid flooding DB requests when parsing a large playlist file for Files.
		// Make this so that also Scrutinizer understands that the return value is always non-null.
		$secret = $this->secret ?? $this->getPrivateSecretFromDb();
		$this->secret = $secret;
		return $secret;
	}

	private function getPrivateSecretFromDb() : string {
		$privateSecretBase64 = $this->config->getAppValue('music', self::PRIVATE_SECRET_KEY);

		if (empty($privateSecretBase64)) {
			$privateSecret = \random_bytes(32);
			$privateSecretBase64 = \base64_encode($privateSecret);
			$this->config->setAppValue('music', self::PRIVATE_SECRET_KEY, $privateSecretBase64);
		} else {
			$privateSecret = \base64_decode($privateSecretBase64);
		}

		return $privateSecret;
	}

}
