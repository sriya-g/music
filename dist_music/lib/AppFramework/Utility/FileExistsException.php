<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2025, 2026
 */

namespace OCA\Music\AppFramework\Utility;

class FileExistsException extends \RuntimeException {

	public function __construct(
		private string $path,
		private string $altName
	) {
	}

	/**
	 * Get conflicting file path
	 */
	public function getPath() : string {
		return $this->path;
	}

	/**
	 * Get suggested alternative file name to avoid the conflict
	 */
	public function getAltName() : string {
		return $this->altName;
	}
}
