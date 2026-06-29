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

namespace OCA\Music\Utility;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\Db\Cache;

class Concurrency {
	private const SEMAPHORE_KEY_BASE = 0xa5e63947; // arbitrarily selected 32-bit base value

	public function __construct(private Cache $cache, private Logger $logger) {
	}

	public function mutexReserve(string $userId, string $key) : false|\SysvSemaphore {
		if (!\extension_loaded('sysvsem')) {
			$this->logger->warning('PHP extension sysvsem should be installed to guarantee correct behavior');
			return false;
		}

		$mutexKey = self::SEMAPHORE_KEY_BASE + $this->cache->forcedGetId($userId, "mutex_key.$key");
		$mutex = \sem_get($mutexKey);

		if ($mutex !== false) {
			\sem_acquire($mutex);
		} else {
			$this->logger->warning('Failed to acquire the semaphore');
		}

		return $mutex;
	}

	public function mutexRelease(false|\SysvSemaphore $mutex) : void {
		if ($mutex !== false) {
			\sem_release($mutex);
		}
	}
}