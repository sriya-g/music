/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013
 * @copyright Pauli Järvinen 2017 - 2026
 */

import * as ng from 'angular';
import { PlayQueue } from 'shared/playqueue';

class PlayQueueService extends PlayQueue {

	subscribe(name : string, context : any, listener : (...args: any[]) => any) : void {
		super.subscribe(name, context, listener);

		// extend the base implementation with the automatic unsubscribing on scope destruction
		if ('$on' in context) {
			context.$on('$destroy', () => this.unsubscribe(name, context));
		}
	}
}

ng.module('Music').service('playQueueService', [PlayQueueService]);
