/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2026
 */

import { MusicAdminSection } from './MusicAdminSection';

declare function t(app : string, text : string, vars?: Object) : string;

export default class MusicAdminHeaderSection implements MusicAdminSection {
	constructor() {
	}

	mount(element: HTMLElement) {
		const root = document.createElement('div');
		root.classList.add('header-section');
		root.insertAdjacentHTML('afterbegin', `
		<div class="scrobbler-intro">
			<p>${t(
				'music',
				'More administrative settings for Music are available by adding specific key-value pairs to the file <samp>{filename}</samp>. The available keys are documented <a href="{url}" target="_blank">here</a>.',
				{url: 'https://github.com/nc-music/music/wiki/Admin-settings', filename: '<cloud root>/config/config.php'}
			)}</p>
		</div>
		`);
		// DOMPurify removes the target attribute
		// t() doesn't give us access to send args to DOMPurify, so we have to add it later
		root.querySelector('a').target = '_blank';
		element.appendChild(root);
	}
}
