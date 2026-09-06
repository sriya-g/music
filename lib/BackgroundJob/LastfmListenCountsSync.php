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

class LastfmListenCountsSync extends TimedJob {

	public function __construct(ITimeFactory $time = null) {
		if ($time === null) {
			$time = \OC::$server->query(ITimeFactory::class);
		}
		parent::__construct($time);
		// Run every 24 hours (86400 seconds)
		$this->setInterval(86400);
	}

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
					$result = $lastfmService->syncUserListenCounts($userId);
					if ($result['success']) {
						$logger->info("Last.fm listen counts sync for user {$userId}: fetched {$result['total_fetched']}, matched {$result['matched']}, updated {$result['updated']}");
					} else {
						$logger->warning("Last.fm listen counts sync skipped/failed for user {$userId}: " . ($result['message'] ?? 'Unknown reason'));
					}
				} catch (\Throwable $e) {
					$logger->error("Last.fm listen counts sync failed for user {$userId}: " . $e->getMessage(), ['exception' => $e]);
				}
			}
		}
	}
}
