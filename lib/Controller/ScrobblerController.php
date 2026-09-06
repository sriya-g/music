<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @author Pauli Järvinen
 * @copyright Matthew Wells 2025
 * @copyright Pauli Järvinen 2025, 2026
 */

namespace OCA\Music\Controller;

use OCA\Music\Service\Scrobbling\ExternalScrobbler;
use OCA\Music\Service\Scrobbling\ScrobbleServiceException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StandaloneTemplateResponse;
use OCP\IL10N;
use OCP\IRequest;

class ScrobblerController extends Controller {
	/**
	 * @param ExternalScrobbler[] $externalScrobblers
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private IL10N $l10n,
		private ?string $userId,
		private array $externalScrobblers,
	) {
		parent::__construct($appName, $request);
	}

	/** @NoSameSiteCookieRequired */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function handleToken(?string $serviceIdentifier, ?string $token) : StandaloneTemplateResponse {
		$params = [
			'lang'                => $this->l10n->getLanguageCode(),
			'success'             => false,
			'headline'            => $this->l10n->t('Unexpected error'),
			'identifier'          => $serviceIdentifier,
			'getsession_response' => '',
			'instructions'        => $this->l10n->t('Please contact your server administrator for assistance.')
		];

		$response = new StandaloneTemplateResponse($this->appName, 'scrobble-getsession-result', [], 'base');

		if (!$this->userId) {
			$params['getsession_response'] = $this->l10n->t('Not logged in');
			$params['instructions'] = $this->l10n->t('Please log in before attempting to authorize a scrobbler');
			$response->setParams($params);
			return $response;
		}

		$scrobbler = $this->getExternalScrobbler($serviceIdentifier);

		if (!$scrobbler) {
			$params['headline'] = $this->l10n->t('Unknown Service');
			$params['getsession_response'] = $this->l10n->t('Unknown service %s', [$serviceIdentifier]);
			$response->setParams($params);
			return $response;
		}

		try {
			$scrobbler->generateSession($token ?? '', $this->userId);
			$params['success'] = true;
			$params['headline'] = $this->l10n->t('All Set!');
			$params['instructions'] = $this->l10n->t('Your streams will be scrobbled to %s.', [$scrobbler->getName()]);
			$params['getsession_response'] = '';
		} catch (ScrobbleServiceException $e) {
			$params['headline'] = $this->l10n->t('Authentication failure');
			$params['instructions'] = $this->l10n->t('Please review the error message prior to trying again.');
			$params['getsession_response'] = $e->getMessage();
		} catch (\Exception $t) {
			$params['getsession_response'] = $t->getMessage();
		} catch (\TypeError $t) {
			$params['getsession_response'] = $t->getMessage();
		} finally {
			$response->setParams($params);
			return $response;
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setToken(string $serviceIdentifier, string $token): JSONResponse {
		$error = null;

		if (!$this->userId) {
			$error = $this->l10n->t('Not logged in');
		} else {
			$scrobbler = $this->getExternalScrobbler($serviceIdentifier);
			if (!$scrobbler) {
				$error = $this->l10n->t('Unknown service %s', [$serviceIdentifier]);
			} else {
				try {
					$scrobbler->generateSession($token, $this->userId);
				} catch (ScrobbleServiceException $e) {
					$error = $e->getMessage();
				} catch (\Exception $t) {
					$error = $t->getMessage();
				}
			}
		}

		return ($error === null)
			? new JSONResponse(['success' => true])
			: new JSONResponse(['error' => ['message' => $error]]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function clearSession(?string $serviceIdentifier): JSONResponse {
		$error = null;

		if (!$this->userId) {
			$error = $this->l10n->t('Not logged in');
		} else {
			$scrobbler = $this->getExternalScrobbler($serviceIdentifier);
			if (!$scrobbler) {
				$error = $this->l10n->t('Unknown service %s', [$serviceIdentifier]);
			} else {
				try {
					$scrobbler->clearSession($this->userId);
				} catch (\InvalidArgumentException $e) {
					$error = $this->l10n->t('Check the error log for details.');
				}
			}
		}

		return ($error === null)
			? new JSONResponse(['success' => true])
			: new JSONResponse(['error' => ['message' => $error]]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function syncLastfmCounts(
		?string $username = null,
		string $period = 'overall',
		int $limit = 5000,
		bool $createPlaylist = false
	) : JSONResponse {
		if (!$this->userId) {
			return new JSONResponse(['error' => ['message' => $this->l10n->t('Not logged in')]], 401);
		}

		$lastfmService = \OC::$server->query(\OCA\Music\Service\LastfmService::class);
		$result = $lastfmService->syncUserListenCounts(
			$this->userId,
			$username,
			$period,
			$limit,
			$createPlaylist
		);

		if (!$result['success']) {
			return new JSONResponse(['error' => ['message' => $result['message'] ?? 'Sync failed']], 400);
		}

		return new JSONResponse($result);
	}

	private function getExternalScrobbler(?string $serviceIdentifier) : ?ExternalScrobbler {
		foreach ($this->externalScrobblers as $scrobbler) {
			if ($scrobbler->getIdentifier() === $serviceIdentifier) {
				return $scrobbler;
			}
		}
		return null;
	}
}
