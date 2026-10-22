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

use OCA\Music\Service\LastfmService;
use OCP\IConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LastfmPlaylistSync extends BaseCommand {

	public function __construct(
		\OCP\IUserManager $userManager,
		\OCP\IGroupManager $groupManager,
		private LastfmService $lastfmService,
		private IConfig $config,
	) {
		parent::__construct($userManager, $groupManager);
	}

	protected function doConfigure() : void {
		$this
			->setName('music:lastfm-playlist-sync')
			->setDescription('Sync Last.fm recommendations and mix playlists for one or more users')
			->addOption(
				'force',
				'f',
				InputOption::VALUE_NONE,
				'Force sync even if playlists were already synced within the last 7 days'
			);
	}

	protected function doExecute(InputInterface $input, OutputInterface $output, array $users) : void {
		$force = (bool)$input->getOption('force');

		if ($input->getOption('all')) {
			$this->userManager->callForAllUsers(function($user) use ($output, $force) {
				$this->syncForUser($user->getUID(), $output, $force);
			});
		} else {
			foreach ($users as $userId) {
				$this->syncForUser($userId, $output, $force);
			}
		}
	}

	private function syncForUser(string $userId, OutputInterface $output, bool $force) : void {
		$hasLastfmSession = (bool)$this->config->getUserValue($userId, 'music', 'lastfm.scrobbleSessionKey');
		$lastfmUser = $this->config->getUserValue($userId, 'music', 'lastfm.username');
		if (!$hasLastfmSession && !$lastfmUser) {
			$output->writeln("User <comment>$userId</comment> has no Last.fm connection or username, skipping.");
			return;
		}

		$output->writeln("Syncing Last.fm recommendation and mix playlists for <info>$userId</info>...");
		$logCallback = function(string $msg) use ($output) {
			$output->writeln("  <comment>{$msg}</comment>");
		};

		try {
			$result = $this->lastfmService->syncStationPlaylists($userId, null, $force, $logCallback);
			if ($result['success']) {
				if (!empty($result['skipped'])) {
					$output->writeln("  <info>Skipped:</info> " . ($result['message'] ?? 'Already synced recently.'));
				} else {
					$output->writeln("  <info>Success:</info> " . ($result['message'] ?? 'Synced successfully.'));
					if (!empty($result['playlists'])) {
						foreach ($result['playlists'] as $type => $info) {
							$output->writeln("    - <info>{$info['name']}</info>: {$info['matched_tracks']}/{$info['total_tracks']} tracks matched, saved to {$info['m3u_file']}");
						}
					}
				}
			} else {
				$output->writeln("  <error>Failed: " . ($result['message'] ?? 'Unknown error') . "</error>");
			}
		} catch (\Throwable $e) {
			$output->writeln("  <error>Failed: " . $e->getMessage() . "</error>");
		}
	}
}
