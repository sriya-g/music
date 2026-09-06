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

class LastfmListenCountsSync extends BaseCommand {

	public function __construct(
		\OCP\IUserManager $userManager,
		\OCP\IGroupManager $groupManager,
		private IConfig $config,
		private LastfmService $lastfmService
	) {
		parent::__construct($userManager, $groupManager);
	}

	protected function doConfigure() : void {
		$this
			->setName('music:lastfm-sync-counts')
			->setAliases(['music:lastfm-listen-counts'])
			->setDescription('Sync Last.fm listen counts into Nextcloud Music for "most listened"')
			->addOption(
				'username',
				'u',
				InputOption::VALUE_REQUIRED,
				'Specific Last.fm username to fetch listens for (defaults to connected Last.fm account)'
			)
			->addOption(
				'period',
				'p',
				InputOption::VALUE_REQUIRED,
				'Time period for top tracks: overall, 7day, 1month, 3month, 6month, 12month',
				'overall'
			)
			->addOption(
				'limit',
				'l',
				InputOption::VALUE_REQUIRED,
				'Maximum number of tracks to fetch from Last.fm',
				'5000'
			)
			->addOption(
				'create-playlist',
				null,
				InputOption::VALUE_NONE,
				'Also create or update a "Most Listened (Last.fm)" playlist'
			)
			->addOption(
				'force',
				null,
				InputOption::VALUE_NONE,
				'Update play counts even if local play count is higher'
			);
	}

	protected function doExecute(InputInterface $input, OutputInterface $output, array $users) : void {
		$username = $input->getOption('username');
		$period = (string)$input->getOption('period');
		$limit = (int)$input->getOption('limit');
		$createPlaylist = (bool)$input->getOption('create-playlist');
		$onlyIfGreater = !(bool)$input->getOption('force');

		if ($input->getOption('all')) {
			$this->userManager->callForAllUsers(function($user) use ($output, $username, $period, $limit, $createPlaylist, $onlyIfGreater) {
				$this->syncForUser($user->getUID(), $output, $username, $period, $limit, $createPlaylist, $onlyIfGreater);
			});
		} else {
			foreach ($users as $userId) {
				$this->syncForUser($userId, $output, $username, $period, $limit, $createPlaylist, $onlyIfGreater);
			}
		}
	}

	private function syncForUser(
		string $userId,
		OutputInterface $output,
		?string $username,
		string $period,
		int $limit,
		bool $createPlaylist,
		bool $onlyIfGreater
	) : void {
		$output->writeln("Syncing Last.fm listen counts for user <info>$userId</info>...");

		$logCallback = function(string $msg) use ($output) {
			$output->writeln("  <comment>$msg</comment>");
		};

		try {
			$result = $this->lastfmService->syncUserListenCounts(
				$userId,
				$username,
				$period,
				$limit,
				$createPlaylist,
				$onlyIfGreater,
				$logCallback
			);

			if ($result['success']) {
				$output->writeln("  <info>Success:</info> Fetched {$result['total_fetched']} tracks, matched {$result['matched']}, updated {$result['updated']} play counts.");
				if (!empty($result['playlist_id'])) {
					$output->writeln("  <info>Playlist:</info> Updated 'Most Listened (Last.fm)' (ID {$result['playlist_id']})");
				}
			} else {
				$output->writeln("  <error>Failed: " . ($result['message'] ?? 'Unknown error') . "</error>");
			}
		} catch (\Throwable $e) {
			$output->writeln("  <error>Error: " . $e->getMessage() . "</error>");
		}
	}
}
