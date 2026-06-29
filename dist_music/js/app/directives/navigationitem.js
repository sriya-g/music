/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright 2017 - 2026 Pauli Järvinen
 */

angular.module('Music').directive('navigationItem', function() {
	return {
		scope: {
			text: '=',
			icon: '=',
			destination: '=',
			playlist: '='
		},
		transclude: true,
		templateUrl: 'navigationitem.html',
		replace: true
	};
});
