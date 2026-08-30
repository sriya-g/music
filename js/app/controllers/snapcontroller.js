/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2026
 */

import * as SnapJs from 'vendor/snapjs/snap';
import { isRTL } from '@nextcloud/l10n';

angular.module('Music').controller('SnapController', ['$rootScope', '$scope',
function ($rootScope, $scope) {
	const SNAPPER_CLOSE = 'closed';
	const SNAPPER_OPEN = isRTL() ? 'right' : 'left';
	const SNAPPER_DISABLED_STATE = isRTL() ? 'left' : 'right';


	const snapper = new SnapJs.Snap({
		element: document.getElementById('app-content'),
		disable: SNAPPER_DISABLED_STATE,
		maxPosition: 300,
		minPosition: -300, // used for RTL
		minDragDistance: 100
	});

	let enabled = false;
	let gestureSuspended = false; // drag event handling temporarily suspended
	let openedByItemDrag = false;

	function open() {
		if (enabled && snapper.state().state == SNAPPER_CLOSE) {
			snapper.open(SNAPPER_OPEN);
		}
	}

	function close() {
		if (enabled && snapper.state().state == SNAPPER_OPEN) {
			snapper.close();
			// Remove any active input focus to ensure that the focus is not left to an input field within the collapsed snapper
			$(document.activeElement).trigger('blur');
		}
	}

	$scope.toggle = () => {
		if (snapper.state().state == SNAPPER_OPEN) {
			snapper.close();
		} else {
			snapper.open(SNAPPER_OPEN);
		}
	};

	$rootScope.subscribe('closeSnapper', $scope, close);
	$rootScope.subscribe('openSnapper', $scope, open);

	// Dragging a song/album/etc. over the navigation toggle pops the navigation pane open.
	// Subsequently, ending the drag closes the navigation pane.
	$scope.itemDragToToggle = () => {
		if (!openedByItemDrag) {
			openedByItemDrag = true;
			open();
		}
	};

	document.addEventListener('dragend', () => {
		if (openedByItemDrag) {
			openedByItemDrag = false;
			close();
		}
	});

	const toggleSnapperOnSize = () => {
		if ($(window).width() >= 1024) {
			snapper.close();
			snapper.disable();
			enabled = false;
		} else {
			snapper.enable();
			enabled = true;
		}
	};

	$(window).on('resize', _.debounce(toggleSnapperOnSize, 250));

	// initial call
	toggleSnapperOnSize();

	// The swipe detection of the snapper is disabled while dragging drag-and-droppable UI elements
	$rootScope.subscribe('ANGULAR_DRAG_START', $scope, () => {
		if (enabled) {
			gestureSuspended = true;
			snapper.disable();
		}
	});

	$rootScope.subscribe('ANGULAR_DRAG_END', $scope, () => {
		if (gestureSuspended) {
			gestureSuspended = false;
			if (enabled) {
				snapper.enable();
			}
		}
	});
}]);
