/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2020 - 2026
 */


angular.module('Music').controller('GenresViewController', [
	'$rootScope', '$scope', 'playQueueService', 'libraryService', 'libraryFactory', '$timeout',
	function ($rootScope, $scope, playQueueService, libraryService, libraryFactory, $timeout) {

		const THIS_VIEW_ID = $scope.getCurrentViewId();

		$scope.genres = null;

		// When making the view visible, the genres are added incrementally step-by-step.
		// The purpose of this is to keep the browser responsive even in case the view contains
		// an enormous amount of genres (like several thousands).
		const INCREMENTAL_LOAD_STEP = 1000;
		$scope.incrementalLoadLimit = 0;

		function playPlaylist(listId, tracks, startFromTrackId = undefined) {
			let startIndex = null;
			if (startFromTrackId !== undefined) {
				startIndex = _.findIndex(tracks, (i) => i.track.id == startFromTrackId);
			}
			playQueueService.setPlaylist(listId, tracks, startIndex);
			playQueueService.publish('play');
		}

		$scope.onGenreTitleClick = function(genre) {
			playPlaylist('genre-' + genre.id, genre.tracks);
		};

		$scope.onTrackClick = function(trackId) {
			// play/pause if currently playing item clicked
			const currentTrack = $scope.$parent.currentTrack;
			if (currentTrack && currentTrack.id === trackId && currentTrack.type == 'song') {
				playQueueService.publish('togglePlayback');
			}
			// on any other list item, start playing the genre or whole library from this item
			else {
				let currentListId = playQueueService.getCurrentPlaylistId();
				let genre = libraryService.getTrack(trackId).genre;

				// start playing the genre from this track if the clicked track belongs
				// to genre which is the current play scope
				if (currentListId === 'genre-' + genre.id) {
					playPlaylist(currentListId, genre.tracks, trackId);
				}
				// on any other track, start playing the collection from this track
				else {
					playPlaylist('genres', libraryService.getTracksInGenreOrder(), trackId);
				}
			}
		};

		function updateHighlight(playlistId) {
			// remove any previous highlight
			$('.highlight').removeClass('highlight');

			// add highlighting if an individual genre is being played
			if (playlistId?.startsWith('genre-')) {
				$('#' + playlistId).addClass('highlight');
			}
		}

		/**
		 * Gets track data to be displayed in the tracklist directive
		 */
		$scope.getTrackData = function(listItem, index, _scope) {
			let track = listItem.track;
			return {
				title: track.title,
				title2: track.artist.name,
				tooltip: track.title,
				tooltip2: track.artist.name,
				number: index + 1,
				id: track.id,
				art: track.album
			};
		};

		function getDraggable(type, draggedElementId) {
			let draggable = {};
			draggable[type] = draggedElementId;
			return draggable;
		}

		$scope.getTrackDraggable = function(trackId) {
			return getDraggable('track', trackId);
		};

		$scope.getGenreDraggable = function(genre) {
			return getDraggable('genre', genre.id);
		};

		/**
		 * Two functions for the alphabet-navigation directive integration
		 */
		$scope.getGenreName = function(index) {
			// Substitute the empty string used on unknown genre with a character from
			// the private use area. This should sort after alphabet regardless of the
			// locale settings.
			return $scope.genres[index].name || '';
		};
		$scope.getGenreElementId = function(index) {
			return 'genre-' + $scope.genres[index].id;
		};

		playQueueService.subscribe('playlistEnded', $scope, () => updateHighlight(null));

		playQueueService.subscribe('playlistChanged', $scope, (playlistId) => updateHighlight(playlistId));

		$rootScope.subscribe('scrollToTrack', $scope, (_event, trackId) => {
			if ($scope.$parent) {
				let elementId = 'track-' + trackId;
				// If the track element is hidden (collapsed), scroll to the genre
				// element instead
				let trackElem = $('#' + elementId);
				if (trackElem.length === 0 || !trackElem.is(':visible')) {
					let genre = libraryService.getTrack(trackId).genre; 
					elementId = 'genre-' + genre.id;
				}
				$scope.$parent.scrollToItem(elementId);
			}
		});

		function initView() {
			$scope.genres = null;
			libraryFactory.getGenres().then((genres) => {
				$scope.genres = genres;
				$scope.incrementalLoadLimit = 0;
				$timeout(showMore);
			});
		}
		initView();

		libraryFactory.subscribe('collectionUpdating', $scope, initView);

		/**
		 * Increase number of shown genres asynchronously step-by-step until
		 * they are all visible. This is to avoid script hanging up for too
		 * long on huge collections.
		 */
		function showMore() {
			// show more entries only if the view is not already (being) deactivated
			if ($scope.$parent) {
				$scope.incrementalLoadLimit += INCREMENTAL_LOAD_STEP;
				if ($scope.incrementalLoadLimit < $scope.genres.length) {
					$timeout(showMore);
				} else {
					updateHighlight(playQueueService.getCurrentPlaylistId());
					$rootScope.$emit('viewActivated', THIS_VIEW_ID);
				}
			}
		}
	}
]);
