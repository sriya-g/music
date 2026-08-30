/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Matthew Wells 2026
 * @copyright Pauli Järvinen 2026
 */

OCA.Music instanceof Object || (OCA.Music = {});

import MusicAdmin from './admin/MusicAdmin';
import MusicAdminHeaderSection from './admin/MusicAdminHeaderSection';
import ScrobblersAdmin from './admin/ScrobblersAdmin';
import('./shared/dialogs');

const app = new MusicAdmin([MusicAdminHeaderSection, ScrobblersAdmin]);
app.mount(document.querySelector('#admin-music'));
/**
 * `require` all modules in the given webpack context
 */
function requireAll(context) {
	context.keys().forEach(context);
}


requireAll(require.context('../css/admin', false));