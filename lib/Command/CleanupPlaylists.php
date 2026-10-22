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

use OCA\Music\BusinessLayer\PlaylistBusinessLayer;
use OCA\Music\Service\LastfmService;
use OCA\Music\Service\Scrobbling\ListenBrainzScrobbler;
use OCP\IConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupPlaylists extends BaseCommand {

	public function __construct(
		\OCP\IUserManager $userManager,
		\OCP\IGroupManager $groupManager,
		private PlaylistBusinessLayer $playlistBusinessLayer,
		private LastfmService $lastfmService,
		private IConfig $config,
	) {
		parent::__construct($userManager, $groupManager);
	}

	protected function doConfigure() : void {
		$this
			->setName('music:cleanup-playlists')
			->setDescription('Clean up playlists older than a specified duration and limit Last.fm playlist')
			->addOption(
				'days',
				'd',
				InputOption::VALUE_REQUIRED,
				'Age threshold in days (playlists older than this will be removed)',
				'7'
			)
			->addOption(
				'all-types',
				null,
				InputOption::VALUE_NONE,
				'Remove ANY playlist older than --days (by default, only auto-generated/daily/weekly/dated playlists are removed)'
			)
			->addOption(
				'dry-run',
				null,
				InputOption::VALUE_NONE,
				'List playlists that would be deleted without actually deleting them'
			)
			->addOption(
				'limit-most-listened',
				null,
				InputOption::VALUE_REQUIRED,
				'Limit "Most Listened (Last.fm)" playlist to the specified number of top tracks (set to 0 to skip)',
				'200'
			);
	}

	protected function doExecute(InputInterface $input, OutputInterface $output, array $users) : void {
		$days = (int)$input->getOption('days');
		$allTypes = (bool)$input->getOption('all-types');
		$dryRun = (bool)$input->getOption('dry-run');
		$mostListenedLimit = (int)$input->getOption('limit-most-listened');

		if ($input->getOption('all')) {
			$this->userManager->callForAllUsers(function($user) use ($output, $days, $allTypes, $dryRun, $mostListenedLimit) {
				$this->cleanupForUser($user->getUID(), $output, $days, $allTypes, $dryRun, $mostListenedLimit);
			});
		} else {
			foreach ($users as $userId) {
				$this->cleanupForUser($userId, $output, $days, $allTypes, $dryRun, $mostListenedLimit);
			}
		}
	}

	private function cleanupForUser(
		string $userId,
		OutputInterface $output,
		int $days,
		bool $allTypes,
		bool $dryRun,
		int $mostListenedLimit
	) : void {
		$prefix = $dryRun ? '<comment>[Dry Run]</comment> ' : '';
		$output->writeln("{$prefix}Cleaning up playlists older than <info>{$days} days</info> for user <info>{$userId}</info>...");

		// 1. Limit "Most Listened (Last.fm)" playlist if requested
		if ($mostListenedLimit > 0) {
			if ($dryRun) {
				$output->writeln("  <comment>[Dry Run] Would check and limit 'Most Listened (Last.fm)' to top {$mostListenedLimit} songs</comment>");
			} else {
				$limitResult = $this->lastfmService->limitMostListenedPlaylist($userId, $mostListenedLimit);
				if ($limitResult['found']) {
					if ($limitResult['previous_count'] > $limitResult['new_count']) {
						$output->writeln("  <info>Last.fm Playlist:</info> Trimmed 'Most Listened (Last.fm)' from {$limitResult['previous_count']} to {$limitResult['new_count']} songs.");
					} else {
						$output->writeln("  <info>Last.fm Playlist:</info> 'Most Listened (Last.fm)' currently has {$limitResult['new_count']} songs (within limit of {$mostListenedLimit}).");
					}
				}
			}
		}

		$logCallback = function(string $msg) use ($output) {
			$output->writeln("  <comment>{$msg}</comment>");
		};

		// Also clean up old Last.fm station playlists (Recommendations / Mix)
		$lastfmCleanup = $this->lastfmService->cleanupOldStationPlaylists($userId, $days, $dryRun, $logCallback);
		$deletedLastfmCount = \count($lastfmCleanup['deleted_playlists']);
		if ($deletedLastfmCount > 0) {
			$actionWord = $dryRun ? 'Would remove' : 'Removed';
			$output->writeln("  <info>Last.fm Station Playlists:</info> {$actionWord} {$deletedLastfmCount} old station playlist(s).");
		}

		// 2. Try to use ListenBrainzScrobbler::cleanupOldPlaylists if available
		$listenBrainzScrobbler = $this->getListenBrainzScrobbler();

		if ($listenBrainzScrobbler) {
			$result = $listenBrainzScrobbler->cleanupOldPlaylists($userId, $days, $allTypes, $dryRun, $logCallback);
			$deletedPlCount = \count($result['deleted_playlists']);

			if ($deletedPlCount > 0) {
				$output->writeln("  <info>Result:</info> Removed {$deletedPlCount} old playlist(s) from the music server.");
			} else {
				$output->writeln("  <info>Result:</info> No playlists older than {$days} days found to clean up.");
			}
		} else {
			// Direct fallback using PlaylistBusinessLayer
			$deletedCount = $this->directCleanup($userId, $days, $allTypes, $dryRun, $output);
			if ($deletedCount > 0) {
				$output->writeln("  <info>Result:</info> Removed {$deletedCount} old playlist(s).");
			} else {
				$output->writeln("  <info>Result:</info> No playlists older than {$days} days found to clean up.");
			}
		}
	}

	private function directCleanup(string $userId, int $days, bool $allTypes, bool $dryRun, OutputInterface $output) : int {
		$allPlaylists = $this->playlistBusinessLayer->findAll($userId);
		$now = \time();
		$cutoffSeconds = $days * 86400;
		$deleted = 0;

		foreach ($allPlaylists as $playlist) {
			$name = $playlist->getName() ?? '';
			$id = $playlist->getId();

			$isEligible = $allTypes;
			if (!$isEligible) {
				if (\stripos($name, '(ListenBrainz)') !== false ||
					\stripos($name, 'Daily Jams') !== false ||
					\stripos($name, 'Weekly Jams') !== false ||
					\stripos($name, 'Weekly Exploration') !== false ||
					\preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $name)) {
					$isEligible = true;
				}
			}

			if (!$isEligible) {
				continue;
			}

			$playlistDate = null;
			if (\preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $name, $matches)) {
				$playlistDate = \strtotime($matches[1]);
			}
			if ($playlistDate === null || $playlistDate <= 0) {
				$created = $playlist->getCreated();
				if ($created instanceof \DateTimeInterface) {
					$playlistDate = $created->getTimestamp();
				} elseif (\is_string($created) && !empty($created)) {
					$playlistDate = \strtotime($created);
				}
			}

			if ($playlistDate !== null && $playlistDate > 0 && ($now - $playlistDate > $cutoffSeconds)) {
				$ageDays = \round(($now - $playlistDate) / 86400, 1);
				$tracks = $playlist->getTrackCount();

				if ($dryRun) {
					$output->writeln("  <comment>[Dry Run] Would delete playlist '{$name}' (ID {$id}, {$tracks} tracks, age {$ageDays} days)</comment>");
				} else {
					$this->playlistBusinessLayer->delete($id, $userId);
					$output->writeln("  <info>Deleted old playlist '{$name}' (ID {$id}, {$tracks} tracks, age {$ageDays} days)</info>");
				}
				$deleted++;
			}
		}

		return $deleted;
	}

	private function getListenBrainzScrobbler() : ?ListenBrainzScrobbler {
		try {
			$app = \OC::$server->query(\OCA\Music\AppInfo\Application::class);
			$scrobblers = $app->get('externalScrobblers');
			foreach ($scrobblers as $scrobbler) {
				if ($scrobbler instanceof ListenBrainzScrobbler) {
					return $scrobbler;
				}
			}
		} catch (\Throwable $e) {
			// Container lookup failed
		}
		return null;
	}
}
