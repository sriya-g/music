<?php declare(strict_types=1);
/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Alessandro Cosentino <cosenal@gmail.com>
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Alessandro Cosentino 2012
 * @copyright Bernhard Posselt 2012, 2014
 * @copyright Pauli Järvinen 2018 - 2026
 */

namespace OCA\Music\AppFramework\Core;

use Psr\Log\LoggerInterface;

class Logger {

	public function __construct(
		protected string $appName,
		protected LoggerInterface $logger
	) {
	}

	public function emergency(string $message) : void {
		$this->logger->emergency($message, ['app' => $this->appName]);
	}

	/**
	 * Action must be taken immediately.
	 */
	public function alert(string $message) : void {
		$this->logger->alert($message, ['app' => $this->appName]);
	}

	/**
	 * Critical conditions.
	 */
	public function critical(string $message) : void {
		$this->logger->critical($message, ['app' => $this->appName]);
	}

	/**
	 * Runtime errors that do not require immediate action but should typically
	 * be logged and monitored.
	 */
	public function error(string $message) : void {
		$this->logger->error($message, ['app' => $this->appName]);
	}

	/**
	 * Exceptional occurrences that are not errors.
	 */
	public function warning(string $message) : void {
		$this->logger->warning($message, ['app' => $this->appName]);
	}

	/**
	 * Normal but significant events.
	 */
	public function notice(string $message) : void {
		$this->logger->notice($message, ['app' => $this->appName]);
	}

	/**
	 * Interesting events.
	 */
	public function info(string $message) : void {
		$this->logger->info($message, ['app' => $this->appName]);
	}

	/**
	 * Detailed debug information.
	 */
	public function debug(string $message) : void {
		$this->logger->debug($message, ['app' => $this->appName]);
	}

}
