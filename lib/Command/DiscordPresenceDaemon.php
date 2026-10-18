<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\Command;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Service\Discord\DiscordPresenceService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DiscordPresenceDaemon extends BaseCommand {

	private const DEFAULT_IDLE_TIMEOUT = 10;

	public function __construct(
		\OCP\IUserManager $userManager,
		\OCP\IGroupManager $groupManager,
		private IConfig $config,
		private ICrypto $crypto,
		private TrackBusinessLayer $trackBusinessLayer,
		private DiscordPresenceService $discordPresenceService,
		private Logger $logger,
	) {
		parent::__construct($userManager, $groupManager);
	}

	protected function doConfigure(): void {
		$this
			->setName('music:discord-presence')
			->setAliases(['music:discord-rpc'])
			->setDescription('Run the Discord Rich Presence daemon for Nextcloud Music')
			->addOption(
				'poll-interval',
				'p',
				InputOption::VALUE_REQUIRED,
				'Poll interval in seconds for playback state checks',
				'2'
			)
			->addOption(
				'idle-timeout',
				null,
				InputOption::VALUE_REQUIRED,
				'Seconds after track duration ends before clearing presence',
				(string)self::DEFAULT_IDLE_TIMEOUT
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$targetUsers = [];
		try {
			self::ensureUsersGiven($input);
			$argUsers = $this->getArgumentUsers($input);
			$groupUsers = $this->getArgumentGroupUsers($input);
			$targetUsers = \array_unique(\array_merge($argUsers, $groupUsers));
		} catch (\InvalidArgumentException $e) {
			// If no specific users specified, we check all users with Discord config
			$targetUsers = [];
		}

		$pollInterval = (int)$input->getOption('poll-interval');
		if ($pollInterval < 1) {
			$pollInterval = 2;
		}

		$idleTimeout = (int)$input->getOption('idle-timeout');
		if ($idleTimeout < 1) {
			$idleTimeout = self::DEFAULT_IDLE_TIMEOUT;
		}

		$output->writeln('<info>Starting Nextcloud Music Discord Rich Presence daemon...</info>');
		$output->writeln("Poll interval: {$pollInterval}s, Idle timeout: {$idleTimeout}s");

		$running = true;
		if (\function_exists('pcntl_signal')) {
			\pcntl_async_signals(true);
			\pcntl_signal(SIGINT, function () use (&$running, $output) {
				$output->writeln("\n<comment>Interrupt received, shutting down...</comment>");
				$running = false;
			});
			\pcntl_signal(SIGTERM, function () use (&$running, $output) {
				$output->writeln("\n<comment>Termination received, shutting down...</comment>");
				$running = false;
			});
		}

		while ($running) {
			// Determine which users to process
			$usersToProcess = $targetUsers;
			if (empty($usersToProcess)) {
				$usersToProcess = $this->getUsersWithDiscordConfig();
			}

			if (empty($usersToProcess)) {
				// Wait and check again
				\sleep($pollInterval);
				continue;
			}

			foreach ($usersToProcess as $userId) {
				$userConfig = $this->getUserDiscordConfig($userId);
				if (empty($userConfig['clientId']) || empty($userConfig['userToken'])) {
					continue;
				}

				// Service gateway ticks (heartbeats & message pump)
				$this->discordPresenceService->tickUser($userId);

				// Check playback status
				try {
					$nowPlaying = $this->trackBusinessLayer->getNowPlaying($userId);
				} catch (\Throwable $e) {
					$nowPlaying = null;
				}

				$now = \time();
				if ($nowPlaying !== null) {
					$track = $nowPlaying['track'];
					$timeOfPlay = (int)$nowPlaying['timeOfPlay'];
					$duration = $track->getLength();
					if ($duration <= 0) {
						$duration = 180;
					}

					$isPlaying = ($now <= $timeOfPlay + $duration + $idleTimeout);

					if ($isPlaying) {
						$currentTrackId = $this->discordPresenceService->getCurrentTrackId($userId);
						if ($currentTrackId !== $track->getId()) {
							$output->writeln(
								"[" . \date('Y-m-d H:i:s') . "] <info>[{$userId}]</info> Playing: " .
								"{$track->getTitle()} (ID: {$track->getId()})"
							);
							$this->discordPresenceService->updatePresence($userId, $track, $timeOfPlay, $userConfig);
						}
					} else {
						// Time elapsed beyond track duration + timeout
						if ($this->discordPresenceService->getCurrentTrackId($userId) !== null) {
							$output->writeln(
								"[" . \date('Y-m-d H:i:s') . "] <comment>[{$userId}]</comment> Track ended, clearing presence."
							);
							$this->discordPresenceService->clearPresence($userId);
						}
					}
				} else {
					// No track in nowPlaying cache
					if ($this->discordPresenceService->getCurrentTrackId($userId) !== null) {
						$output->writeln(
							"[" . \date('Y-m-d H:i:s') . "] <comment>[{$userId}]</comment> Playback stopped, clearing presence."
						);
						$this->discordPresenceService->clearPresence($userId);
					}
				}
			}

			// Sub-divided sleep to keep WebSockets reactive
			$sleepMs = $pollInterval * 1000;
			$stepMs = 250;
			while ($sleepMs > 0 && $running) {
				\usleep($stepMs * 1000);
				$sleepMs -= $stepMs;
				foreach ($usersToProcess as $userId) {
					$this->discordPresenceService->tickUser($userId);
				}
			}
		}

		$output->writeln('<info>Disconnecting Discord sessions...</info>');
		$this->discordPresenceService->disconnectAll();
		$output->writeln('<info>Daemon stopped gracefully.</info>');

		return 0;
	}

	/**
	 * @return string[]
	 */
	private function getUsersWithDiscordConfig(): array {
		$users = [];
		$allUsers = $this->userManager->search('');
		foreach ($allUsers as $user) {
			$uid = $user->getUID();
			$token = $this->config->getUserValue($uid, 'music', 'discord.userToken');
			if (!empty($token)) {
				$users[] = $uid;
			}
		}
		return $users;
	}

	/**
	 * @return array{clientId: string, userToken: string, caaEnabled: bool, activityName: string, customActivityName: string, spotifyLinks: bool}
	 */
	private function getUserDiscordConfig(string $userId): array {
		$clientId = (string)$this->config->getUserValue($userId, 'music', 'discord.clientId', '');
		$encryptedToken = (string)$this->config->getUserValue($userId, 'music', 'discord.userToken', '');
		$userToken = '';
		if (!empty($encryptedToken)) {
			try {
				$userToken = $this->crypto->decrypt($encryptedToken, $userId . $this->config->getSystemValue('secret'));
			} catch (\Throwable $e) {
				$userToken = '';
			}
		}

		$caaEnabled = (bool)$this->config->getUserValue($userId, 'music', 'discord.caaEnabled', 'true');
		$activityName = (string)$this->config->getUserValue($userId, 'music', 'discord.activityName', 'Default');
		$customActivityName = (string)$this->config->getUserValue($userId, 'music', 'discord.customActivityName', 'Nextcloud Music');
		$spotifyLinks = (bool)$this->config->getUserValue($userId, 'music', 'discord.spotifyLinks', 'false');

		return [
			'clientId' => $clientId,
			'userToken' => $userToken,
			'caaEnabled' => $caaEnabled,
			'activityName' => $activityName,
			'customActivityName' => $customActivityName,
			'spotifyLinks' => $spotifyLinks,
		];
	}
}
