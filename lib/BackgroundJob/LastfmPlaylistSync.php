<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\BackgroundJob;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\AppInfo\Application;
use OCA\Music\Service\LastfmService;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

class LastfmPlaylistSync extends TimedJob {

	public function __construct(ITimeFactory $time = null) {
		if ($time === null) {
			$time = \OC::$server->query(ITimeFactory::class);
		}
		parent::__construct($time);
		// Run daily (86400 seconds)
		$this->setInterval(86400);
	}

	/**
	 * @param mixed $arguments
	 * @return void
	 */
	public function run($arguments) {
		$app = \OC::$server->query(Application::class);
		$logger = $app->get(Logger::class);
		$logger->debug('Run ' . static::class);

		$config = $app->get(IConfig::class);
		$userManager = \OC::$server->getUserManager();
		$lastfmService = $app->get(LastfmService::class);

		// Find all users and check if they have Last.fm session or configured username
		$users = $userManager->search('');
		foreach ($users as $user) {
			$userId = $user->getUID();
			$hasLastfmSession = (bool)$config->getUserValue($userId, 'music', 'lastfm.scrobbleSessionKey');
			$lastfmUser = $config->getUserValue($userId, 'music', 'lastfm.username');
			if ($hasLastfmSession || $lastfmUser) {
				try {
					$result = $lastfmService->syncStationPlaylists($userId);
					if ($result['success']) {
						if (!empty($result['skipped'])) {
							$logger->info("Last.fm playlist sync skipped for user {$userId}: " . ($result['message'] ?? ''));
						} else {
							$logger->info("Last.fm playlist sync completed for user {$userId}: " . ($result['message'] ?? ''));
						}
					} else {
						$logger->warning("Last.fm playlist sync failed for user {$userId}: " . ($result['message'] ?? 'Unknown reason'));
					}
				} catch (\Throwable $e) {
					$logger->error("Last.fm playlist sync failed for user {$userId}: " . $e->getMessage(), ['exception' => $e]);
				}
			}
		}
	}
}
