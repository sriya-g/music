<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013, 2014
 * @copyright Pauli Järvinen 2017 - 2026
 */

namespace OCA\Music\Controller;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\Db\AmpacheSessionMapper;
use OCA\Music\Db\AmpacheUserMapper;
use OCA\Music\Http\ErrorResponse;
use OCA\Music\Service\LibrarySettings;
use OCA\Music\Service\Scanner;
use OCA\Music\Utility\AppInfo;
use OCA\Music\Utility\StringUtil;
use OCA\Music\Service\Scrobbling\ExternalScrobbler;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\HintException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

class SettingController extends Controller {
	private const DEFAULT_PASSWORD_LENGTH = 10;
	/* Character set without look-alike characters. Similar but even more stripped set would be found
	 * on Nextcloud as ISecureRandom::CHAR_HUMAN_READABLE but that's not available on ownCloud. */
	private const API_KEY_CHARSET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/**
	 * @param ExternalScrobbler[] $externalScrobblers
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private AmpacheSessionMapper $ampacheSessionMapper,
		private AmpacheUserMapper $ampacheUserMapper,
		private Scanner $scanner,
		private ?string $userId,
		private LibrarySettings $librarySettings,
		private ISecureRandom $secureRandom,
		private IURLGenerator $urlGenerator,
		private Logger $logger,
		private array $externalScrobblers,
		private IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	private function user() : string {
		// The $userId may be null in constructor (if user session has been terminated) but none of our
		// public methods should get called in such case. Assure this also for Scrutinizer.
		\assert($this->userId !== null);
		return $this->userId;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession] // to keep the session reserved while execution in progress
	public function userPath(string $value) : JSONResponse {
		$prevPath = $this->librarySettings->getPath($this->user());
		$success = $this->librarySettings->setPath($this->user(), $value);

		if ($success) {
			$this->scanner->updatePath($prevPath, $value, $this->user());
		}

		return new JSONResponse(['success' => $success]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function userExcludedPaths(array $value) : JSONResponse {
		$success = $this->librarySettings->setExcludedPaths($this->user(), $value);
		return new JSONResponse(['success' => $success]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function enableScanMetadata(bool $value) : JSONResponse {
		$this->librarySettings->setScanMetadataEnabled($this->user(), $value);
		return new JSONResponse(['success' => true]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ignoredArticles(array $value) : JSONResponse {
		$this->librarySettings->setIgnoredArticles($this->user(), $value);
		return new JSONResponse(['success' => true]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getAll() : JSONResponse {
		return new JSONResponse([
			'path' => $this->librarySettings->getPath($this->user()),
			'excludedPaths' => $this->librarySettings->getExcludedPaths($this->user()),
			'scanMetadata' => $this->librarySettings->getScanMetadataEnabled($this->user()),
			'ignoredArticles' => $this->librarySettings->getIgnoredArticles($this->user()),
			'ampacheUrl' => $this->getAmpacheUrl(),
			'subsonicUrl' => $this->getSubsonicUrl(),
			'ampacheKeys' => $this->ampacheUserMapper->getAll($this->user()),
			'appVersion' => AppInfo::getVersion(),
			'user' => $this->user(),
			'scrobblers' => $this->getScrobbleAuth()
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getUserKeys() : JSONResponse {
		return new JSONResponse($this->ampacheUserMapper->getAll($this->user()));
	}

	private function getAmpacheUrl() : string {
		return (string)\str_replace(
			'/server/xml.server.php',
			'',
			$this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('music.ampache.xmlApi'))
		);
	}

	private function getSubsonicUrl() : string {
		return (string)\str_replace(
			'/rest/dummy',
			'',
			$this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute(
				'music.subsonic.handleRequest',
				['method' => 'dummy']
			))
		);
	}

	private function getScrobbleAuth(): array {
		$services = [];
		foreach ($this->externalScrobblers as $scrobbler) {
			$tokenRequestUrl = $scrobbler->getTokenRequestUrl();
			$services[] = [
				'service' => $scrobbler->getName(),
				'identifier' => $scrobbler->getIdentifier(),
				'configured' => $scrobbler->getIdentifier() === 'listenbrainz' ? true : ($tokenRequestUrl && $scrobbler->getApiSecret()),
				'tokenRequestUrl' => $tokenRequestUrl,
				'hasSession' => $scrobbler->getApiSession($this->user()) !== null
			];
		}

		return $services;
	}

	#[NoCSRFRequired]
	public function setScrobblerCredentials(string $serviceIdentifier, string $apiKey = '', string $apiSecret = '') : JSONResponse {
		try {
			$updated = false;
			foreach ($this->externalScrobblers as $externalScrobbler) {
				if ($externalScrobbler->getIdentifier() === $serviceIdentifier) {
					$externalScrobbler->setApiKey($apiKey);
					$externalScrobbler->setApiSecret($apiSecret);
					$updated = true;
					break;
				}
			}

			if (!$updated) {
				return new JSONResponse([
					'error' => $this->l10n->t('Could not find Scrobbler with identifier \'%s\'', [$serviceIdentifier])
				], 404);
			}
		} catch (HintException $e) {
			return new JSONResponse([
				'error' => $this->l10n->t('Unable to update config file. Error from Server: \'%s\'', [$e->getMessage()])
			], 403);
		} catch (\Throwable $t) {
			return new JSONResponse([
				'error' => $this->l10n->t('Unexpected error. Error from server: \'%s\'', [$t->getMessage()])
			], 400);
		}
		return new JSONResponse([]);
	}

	private function storeUserKey(?string $description, string $password) : ?int {
		$hash = \hash('sha256', $password);
		$description = StringUtil::truncate($description, 64); // some DB setups can't truncate automatically to column max size
		return $this->ampacheUserMapper->addUserKey($this->user(), $hash, $description);
	}

	#[NoAdminRequired]
	public function createUserKey(?int $length, ?string $description) : JSONResponse {
		if ($length === null || $length < self::DEFAULT_PASSWORD_LENGTH) {
			$length = self::DEFAULT_PASSWORD_LENGTH;
		}

		$password = $this->secureRandom->generate($length, self::API_KEY_CHARSET);

		$id = $this->storeUserKey($description, $password);

		if ($id === null) {
			return new ErrorResponse(Http::STATUS_INTERNAL_SERVER_ERROR, 'Error while saving the credentials');
		}

		return new JSONResponse(['id' => $id, 'password' => $password, 'description' => $description], Http::STATUS_CREATED);
	}

	/**
	 * The CORS-version of the key creation function is targeted for external clients. We need separate function
	 * because the CORS middleware blocks the normal internal access on Nextcloud versions older than 25 as well
	 * as on ownCloud 10.0, at least (but not on OC 10.4+).
	 */
	#[NoAdminRequired]
	#[CORS]
	public function createUserKeyCors(?int $length, ?string $description) : JSONResponse {
		return $this->createUserKey($length, $description);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function removeUserKey(int $id) : JSONResponse {
		$this->ampacheSessionMapper->revokeSessions($id);
		$this->ampacheUserMapper->removeUserKey($this->user(), $id);
		return new JSONResponse(['success' => true]);
	}
}
