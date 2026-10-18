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
use OCP\IUserManager;
use OCP\Security\ICrypto;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DiscordPresenceDaemon extends Command {

	private const DEFAULT_IDLE_TIMEOUT = 10;

	public function __construct(
		private IUserManager $userManager,
		private IConfig $config,
		private ICrypto $crypto,
		private TrackBusinessLayer $trackBusinessLayer,
		private DiscordPresenceService $discordPresenceService,
		private Logger $logger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('music:discord-presence')
			->setAliases(['music:discord-rpc'])
			->setDescription('Run the Discord Rich Presence daemon for Nextcloud Music')
			->addOption(
				'user',
				'u',
				InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				'Specify targeted user(s)'
			)
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
			)
			->addOption(
				'test',
				't',
				InputOption::VALUE_NONE,
				'Test Discord connection and credentials with a sample presence update and exit'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$targetUsers = (array)($input->getOption('user') ?: []);
		foreach ($targetUsers as $user) {
			if (!$this->userManager->userExists($user)) {
				$output->writeln("<error>User {$user} does not exist!</error>");
				return 1;
			}
		}

		if ($input->getOption('test')) {
			return $this->runTestMode($targetUsers ?: $this->getUsersWithDiscordConfig(), $output);
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

		$initialUsers = $targetUsers ?: $this->getUsersWithDiscordConfig();
		if (empty($initialUsers)) {
			$output->writeln('<comment>[Notice] No users currently configured with Discord credentials.</comment>');
			$output->writeln('<comment>Go to Nextcloud Music Settings > Discord Rich Presence to enter your Application Client ID and User Token.</comment>');
			$output->writeln('<comment>Daemon will keep running and check periodically for configuration updates...</comment>');
		} else {
			$output->writeln("<info>Found " . \count($initialUsers) . " user(s) with Discord configuration: " . \implode(', ', $initialUsers) . "</info>");
		}

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

		$notifiedWaiting = [];
		$notifiedConfigError = [];

		while ($running) {
			// Determine which users to process
			$usersToProcess = $targetUsers;
			if (empty($usersToProcess)) {
				$usersToProcess = $this->getUsersWithDiscordConfig();
			}

			if (empty($usersToProcess)) {
				\sleep($pollInterval);
				continue;
			}

			foreach ($usersToProcess as $userId) {
				$userConfig = $this->getUserDiscordConfig($userId);
				if (empty($userConfig['clientId']) || empty($userConfig['userToken'])) {
					if (!isset($notifiedConfigError[$userId])) {
						$output->writeln("<error>[{$userId}] Missing Client ID or User Token in Nextcloud Music settings.</error>");
						$notifiedConfigError[$userId] = true;
					}
					continue;
				}
				unset($notifiedConfigError[$userId]);

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
						$notifiedWaiting[$userId] = false;
						$currentTrackId = $this->discordPresenceService->getCurrentTrackId($userId);
						if ($currentTrackId !== $track->getId()) {
							$output->writeln(
								"[" . \date('Y-m-d H:i:s') . "] <info>[{$userId}]</info> Playing: " .
								"{$track->getTitle()} (ID: {$track->getId()})"
							);

							if (!$this->discordPresenceService->isUserConnected($userId)) {
								$output->writeln("[" . \date('Y-m-d H:i:s') . "] <info>[{$userId}]</info> Connecting to Discord Gateway...");
							}

							$updated = $this->discordPresenceService->updatePresence($userId, $track, $timeOfPlay, $userConfig);
							if ($updated) {
								$userInfo = $this->discordPresenceService->getDiscordUserInfo($userId);
								$dUser = $userInfo['username'] ?? '';
								$dMsg = $dUser ? " as @{$dUser}" : "";
								$output->writeln("[" . \date('Y-m-d H:i:s') . "] <info>[{$userId}]</info> Discord presence active{$dMsg}!");
							} else {
								$err = $this->discordPresenceService->getLastAuthError($userId);
								$output->writeln("[" . \date('Y-m-d H:i:s') . "] <error>[{$userId}] Failed to update Discord presence" . ($err ? ": {$err}" : "") . "</error>");
							}
						}
					} else {
						// Time elapsed beyond track duration + timeout
						if ($this->discordPresenceService->getCurrentTrackId($userId) !== null) {
							$output->writeln(
								"[" . \date('Y-m-d H:i:s') . "] <comment>[{$userId}]</comment> Track ended, clearing presence."
							);
							$this->discordPresenceService->clearPresence($userId);
						}
						if (empty($notifiedWaiting[$userId])) {
							$output->writeln("[" . \date('Y-m-d H:i:s') . "] <comment>[{$userId}]</comment> Idle. Play a track in Nextcloud Music to broadcast status.");
							$notifiedWaiting[$userId] = true;
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
					if (empty($notifiedWaiting[$userId])) {
						$output->writeln("[" . \date('Y-m-d H:i:s') . "] <comment>[{$userId}]</comment> Idle. Play a track in Nextcloud Music to broadcast status.");
						$notifiedWaiting[$userId] = true;
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
	 * @param string[] $users
	 */
	private function runTestMode(array $users, OutputInterface $output): int {
		$output->writeln('<info>Running Discord Rich Presence test...</info>');
		if (empty($users)) {
			$output->writeln('<error>No users found with Discord configuration to test!</error>');
			$output->writeln('<comment>Please configure Client ID and User Token in Nextcloud Music settings first.</comment>');
			return 1;
		}

		$allOk = true;
		foreach ($users as $userId) {
			$output->writeln("\n<info>Testing user: [{$userId}]</info>");
			$config = $this->getUserDiscordConfig($userId);
			if (empty($config['clientId'])) {
				$output->writeln('<error>Missing Application Client ID!</error>');
				$allOk = false;
				continue;
			}
			if (empty($config['userToken'])) {
				$output->writeln('<error>Missing or un-decryptable User Token!</error>');
				$allOk = false;
				continue;
			}

			$output->writeln("Application Client ID: {$config['clientId']}");
			$output->writeln("Connecting to Discord Gateway (wss://gateway.discord.gg)...");

			$connected = $this->discordPresenceService->connectUser($userId, $config['clientId'], $config['userToken']);
			if (!$connected) {
				$err = $this->discordPresenceService->getLastAuthError($userId);
				$output->writeln("<error>Connection failed: {$err}</error>");
				if (\str_contains((string)$err, '4004')) {
					$output->writeln('<comment>Notice: Discord rejected your token (4004: Authentication failed).</comment>');
					$output->writeln('<comment>Ensure you pasted your personal Discord User Token, not an Application Bot Token or OAuth Secret.</comment>');
				}
				$allOk = false;
				continue;
			}

			$userInfo = $this->discordPresenceService->getDiscordUserInfo($userId);
			$username = $userInfo['username'] ?? 'Unknown';
			$discrim = !empty($userInfo['discriminator']) && $userInfo['discriminator'] !== '0' ? "#{$userInfo['discriminator']}" : '';
			$discordId = $userInfo['id'] ?? 'Unknown';

			$output->writeln("<info>[OK] Successfully authenticated with Discord Gateway!</info>");
			$output->writeln("<info>Logged in as: {$username}{$discrim} (Discord User ID: {$discordId})</info>");

			$output->writeln("Broadcasting test Rich Presence activity...");
			$testActivity = [
				'application_id' => $config['clientId'],
				'name' => 'Nextcloud Music',
				'type' => 2, // Listening to
				'details' => 'Testing Discord Rich Presence',
				'state' => 'Connected from Nextcloud Music',
				'status_display_type' => 0,
				'timestamps' => [
					'start' => \time() * 1000,
				],
			];

			$sent = $this->discordPresenceService->sendRawPresence($userId, $testActivity);
			if ($sent) {
				$output->writeln("<info>[OK] Test activity broadcasted! Check your Discord profile status.</info>");
				$output->writeln("<comment>Important: Make sure Discord User Settings > Activity Privacy > 'Display current activity as a status message' is enabled in Discord.</comment>");
				$output->writeln("Keeping presence active for 8 seconds...");
				\sleep(8);
				$output->writeln("Clearing test presence...");
				$this->discordPresenceService->clearPresence($userId);
			} else {
				$output->writeln("<error>Failed to send test presence payload.</error>");
				$allOk = false;
			}

			$this->discordPresenceService->disconnectUser($userId);
		}

		$output->writeln("\n<info>Test finished.</info>");
		return $allOk ? 0 : 1;
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
