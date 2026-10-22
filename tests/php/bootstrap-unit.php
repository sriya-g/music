<?php

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvien@gmail.com>
 * @copyright Morris Jobke 2014
 * @copyright Pauli Järvinen 2025
 */

if (\file_exists(__DIR__ . '/../../vendor/autoload.php')) {
	require_once __DIR__ . '/../../vendor/autoload.php';
}

require_once __DIR__ . '/autoloader-unit.php';

class OC {
	public static $server;
	public static $session;
}
