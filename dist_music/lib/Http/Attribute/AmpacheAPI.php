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

namespace OCA\Music\Http\Attribute;

use Attribute;

/**
 * Attribute for controller methods belonging to the Ampache API. Any method decorated with this
 * attribute may be called by an Ampache client with an URL like
 * https://path/to/cloud/index.php/apps/music/ampache/server/xml.server.php?action=methodName or
 * https://path/to/cloud/index.php/apps/music/ampache/server/json.server.php?action=methodName
 * where `methodName` matches the name of the PHP method.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class AmpacheAPI {
}
