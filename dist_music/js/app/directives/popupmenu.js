/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright 2026 Pauli Järvinen
 */

angular.module('Music').directive('popupMenu', ['$rootScope', function($rootScope) {
	return {
		restrict: 'E',
		transclude: true,
		scope: {
			busy: '<',
		},
		link: function(scope) {
			scope.expanded = false;

			scope.onExpansionButtonClick = function(event) {
				scope.expanded = !scope.expanded;
				$rootScope.$emit('popup-menu:close', scope);
				event.stopPropagation();
			};

			$rootScope.$on('popup-menu:close', function(_event, source) {
				if (source !== scope) {
					scope.expanded = false;
				}
			});
		},
		template: `
			<div class="actions" title="" ng-class="{'menu-open': expanded}">
				<span class="icon-more" ng-show="!busy"
					ng-click="onExpansionButtonClick($event)"></span>
				<span class="icon-loading-small" ng-show="busy"></span>
				<div class="popovermenu bubble" ng-show="expanded" ng-click="expanded = false; $event.stopPropagation()">
					<ul>
						<ng-transclude></ng-transclude>
					</ul>
				</div>
			</div>`,
		replace: true
	};
}]);
