/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2023 - 2026
 */


angular.module('Music').controller('SmartListFiltersController', [
	'$scope', '$rootScope', '$timeout', 'libraryFactory', 'gettextCatalog',
	function ($scope, $rootScope, $timeout, libraryFactory, gettextCatalog) {

		$scope.smartListParams = null;
		$scope.fieldsValid = false;
		$scope.allGenres = null;
		$scope.allArtists = null;

		// for artists and genres, the selection box can't use the smartListParams
		// directly as model as we need conversions between string and array formats
		$scope.genres = [];
		$scope.artists = [];
		$scope.composers = [];

		libraryFactory.getSmartListParams().then((params) => {
			$scope.smartListParams = params;
			$scope.genres = params.genres?.split(',') ?? [];
			$scope.artists = params.artists?.split(',') ?? [];
			$scope.composers = params.composers?.split(',') ?? [];
			$scope.fieldsValid = allFieldsValid();
		});

		$timeout(() => {
			$('#filter-genres').chosen();
			$('#filter-artists').chosen();
			$('#filter-composers').chosen();
			$('#filter-history').chosen({allow_single_deselect: true, disable_search: true, placeholder_text_single: ' '});
			$('#filter-favorite').chosen({allow_single_deselect: true, disable_search: true, placeholder_text_single: ' '});
			const $chosenInputs = $('#smartlist-filters .chosen-container');
			const $filterGenres = $('#filter-genres');
			const $filterSize = $('#filter-size');

			$chosenInputs
				.css('width', '') // purge the inline rule to let the CSS define the width
				.css('--border-input', $filterGenres.css('border')) // copy the border style from the input field
				.css('--border-radius-input', $filterGenres.css('border-radius'));

			$filterSize.trigger('focus');
			$timeout(() => {
				$chosenInputs.css('--color-input-border-hover', $filterSize.css('border-color')); // copy the border color of focused input
			});
		});

		function allFieldsValid() {
			let valid = ($scope.smartListParams !== null);

			$('#smartlist-filters input[type=number]').each((_index, elem) =>
				valid &&= elem.checkValidity()
			);
			// the size field must not be empty
			valid &&= ($scope.smartListParams.size > 0);

			return valid;
		}

		$scope.$watchGroup(['fromYear', 'toYear', 'listSize'], () => $scope.fieldsValid = allFieldsValid());

		$scope.onUpdateButton = function() {
			if ($scope.fieldsValid) {
				$scope.smartListParams.genres = $scope.genres.join(',');
				$scope.smartListParams.artists = $scope.artists.join(',');
				$scope.smartListParams.composers = $scope.composers.join(',');
				libraryFactory.reloadSmartList($scope.smartListParams);
				// also navigate to the Smart Playlist view if not already open
				$scope.navigateTo('#smartlist');
			} else {
				OCA.Music.Dialogs.showNotification(gettextCatalog.getString('Check the filter values'));
			}
		};

		function init() {
			libraryFactory.getGenres().then((genres) => {
				$scope.allGenres = genres;
				$timeout(() => $('#filter-genres').trigger('chosen:updated'));
			});
			libraryFactory.getAllArtists().then((artists) => {
				$scope.allArtists = artists;
				$timeout(() => $('#filter-artists').trigger('chosen:updated'));
				$timeout(() => $('#filter-composers').trigger('chosen:updated'));
			});
		}
		init();

		// the artists and genres may be (re)loaded also after this controller has been initialized
		libraryFactory.subscribe('collectionUpdating', $scope, init);
	}
]);
