/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @copyright Matthew Wells 2026
 */

import { MusicAdminSectionConstructor, MusicAdminSection } from './AdminSection'

export default class MusicAdmin {
	#children: Array<MusicAdminSection>;

	constructor(ChildClasses: Array<MusicAdminSectionConstructor>) {
		this.#children = ChildClasses.map(ChildClass => new ChildClass());
	}

	mount(element: HTMLElement) {
		this.#children.forEach(child => child.mount(element));
	}
}
