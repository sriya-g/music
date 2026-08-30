/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @copyright Matthew Wells 2026
 */

export interface MusicAdminSectionConstructor {
	new (): MusicAdminSection;
}

export interface MusicAdminSection {
	mount(element: HTMLElement): void;
}
