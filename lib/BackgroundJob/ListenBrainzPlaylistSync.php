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
use OCA\Music\Service\Scrobbling\ListenBrainzScrobbler;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

class ListenBrainzPlaylistSync extends TimedJob {

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

		// Find the ListenBrainzScrobbler instance
		$scrobblers = $app->get('externalScrobblers');
		$listenBrainzScrobbler = null;
		foreach ($scrobblers as $scrobbler) {
			if ($scrobbler instanceof ListenBrainzScrobbler) {
				$listenBrainzScrobbler = $scrobbler;
				break;
			}
		}

		if (!$listenBrainzScrobbler) {
			$logger->warning('ListenBrainzScrobbler not registered, skipping playlist sync');
			return;
		}

		// Find all users and check if they have ListenBrainz session keys
		$users = $userManager->search('');
		foreach ($users as $user) {
			$userId = $user->getUID();
			$token = $config->getUserValue($userId, 'music', 'listenbrainz.scrobbleSessionKey');
			if ($token) {
				try {
					$listenBrainzScrobbler->syncPlaylists($userId);
				} catch (\Throwable $e) {
					$logger->error("ListenBrainz playlist sync failed for user {$userId}: " . $e->getMessage(), ['exception' => $e]);
				}
			}
		}
	}
}
