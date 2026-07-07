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

namespace OCA\Music\Command;

use OCA\Music\Service\Scrobbling\ListenBrainzScrobbler;
use OCP\IConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListenBrainzPlaylistSync extends BaseCommand {

	public function __construct(
		\OCP\IUserManager $userManager,
		\OCP\IGroupManager $groupManager,
		private IConfig $config
	) {
		parent::__construct($userManager, $groupManager);
	}

	protected function doConfigure() : void {
		$this
			->setName('music:listenbrainz-playlist-sync')
			->setDescription('Sync ListenBrainz playlists for one or more users');
	}

	protected function doExecute(InputInterface $input, OutputInterface $output, array $users) : void {
		$app = \OC::$server->query(\OCA\Music\AppInfo\Application::class);
		$scrobblers = $app->get('externalScrobblers');
		$listenBrainzScrobbler = null;
		foreach ($scrobblers as $scrobbler) {
			if ($scrobbler instanceof ListenBrainzScrobbler) {
				$listenBrainzScrobbler = $scrobbler;
				break;
			}
		}

		if (!$listenBrainzScrobbler) {
			$output->writeln('<error>ListenBrainzScrobbler not registered</error>');
			return;
		}

		if ($input->getOption('all')) {
			$this->userManager->callForAllUsers(function($user) use ($output, $listenBrainzScrobbler) {
				$this->syncForUser($user->getUID(), $listenBrainzScrobbler, $output);
			});
		} else {
			foreach ($users as $userId) {
				$this->syncForUser($userId, $listenBrainzScrobbler, $output);
			}
		}
	}

	private function syncForUser(string $userId, ListenBrainzScrobbler $scrobbler, OutputInterface $output) : void {
		$token = $this->config->getUserValue($userId, 'music', 'listenbrainz.scrobbleSessionKey');
		if (!$token) {
			$output->writeln("User <comment>$userId</comment> has no ListenBrainz connection, skipping.");
			return;
		}

		$output->writeln("Syncing ListenBrainz playlists for <info>$userId</info>...");
		try {
			$scrobbler->syncPlaylists($userId);
			$output->writeln("  <info>Success</info>");
		} catch (\Throwable $e) {
			$output->writeln("  <error>Failed: " . $e->getMessage() . "</error>");
		}
	}
}
