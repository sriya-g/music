/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2019 - 2026
 */


angular.module('Music').controller('FoldersViewController', [
	'$rootScope', '$scope', '$timeout', 'playQueueService', 'libraryService', 'libraryFactory',
	function ($rootScope, $scope, $timeout, playQueueService, libraryService, libraryFactory) {

		const THIS_VIEW_ID = $scope.getCurrentViewId();

		$scope.folders = null;
		$scope.rootFolder = null;

		// When making the view visible, the folders are added incrementally step-by-step.
		// The purpose of this is to keep the browser responsive even in case the view contains
		// an enormous amount of folders (like several thousands).
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

		$scope.onFolderTitleClick = function(folder) {
			playPlaylist('folder-' + folder.id, libraryService.getFolderTracks(folder, !$scope.foldersFlatLayout));
		};

		$scope.onTrackClick = function(trackId) {
			// play/pause if currently playing folder item clicked
			const currentTrack = $scope.$parent.currentTrack;
			if (currentTrack && currentTrack.id === trackId && currentTrack.type == 'song') {
				playQueueService.publish('togglePlayback');
			}
			// on any other list item, start playing from this item
			else {
				// change the track within the playscope if the clicked track belongs to the current folder scope
				if (trackBelongsToPlayingFolder(trackId)) {
					playPlaylist(
						playQueueService.getCurrentPlaylistId(),
						playQueueService.getCurrentPlaylist(),
						trackId);
				}
				// on any other case, start playing the collection from this track
				else {
					playPlaylist('folders', libraryService.getTracksInFolderOrder(!$scope.foldersFlatLayout), trackId);
				}
			}
		};

		function trackBelongsToPlayingFolder(trackId) {
			let currentListId = playQueueService.getCurrentPlaylistId();
			if (currentListId?.startsWith('folder-')) {
				let currentList = playQueueService.getCurrentPlaylist();
				return (0 <= _.findIndex(currentList, ['track.id', trackId]));
			} else {
				return false;
			}
		}

		function updateHighlight(playlistId) {
			// remove any previous highlight
			$('.highlight').removeClass('highlight');

			// add highlighting if an individual folder is being played
			if (playlistId?.startsWith('folder-')) {
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

		$scope.getFolderDraggable = function(folder) {
			return getDraggable('folder', folder.id);
		};

		/**
		 * Two functions for the alphabet-navigation directive integration
		 */
		$scope.getFolderName = function(index) {
			return $scope.folders[index].name;
		};
		$scope.getFolderElementId = function(index) {
			return 'folder-' + $scope.folders[index].id;
		};

		playQueueService.subscribe('playlistEnded', $scope, () => updateHighlight(null));

		playQueueService.subscribe('playlistChanged', $scope, (playlistId) => updateHighlight(playlistId));

		$rootScope.subscribe('scrollToTrack', $scope, (_event, trackId) => {
			if ($scope.$parent) {
				let elementId = 'track-' + trackId;
				// If the track element is hidden (collapsed), scroll to the folder
				// element instead
				let trackElem = $('#' + elementId);
				if (trackElem.length === 0 || !trackElem.is(':visible')) {
					let folder = libraryService.getTrack(trackId).folder; 
					elementId = 'folder-' + folder.id;
				}
				$scope.$parent.scrollToItem(elementId);
			}
		});

		libraryFactory.subscribe('collectionUpdating', $scope, () => {
			$scope.folders = null;
			$scope.rootFolder = null;
			initView();
		});

		function initView() {
			libraryFactory.getRootFolder().then(() => {
				$scope.folders = libraryService.getAllFoldersWithTracks();
				$scope.rootFolder = libraryService.getRootFolder();
				makeContentVisible();
			});
		}
		initView();

		function makeContentVisible() {
			$scope.incrementalLoadLimit = 0;
			if ($scope.foldersFlatLayout) {
				$timeout(showMore);
			} else {
				$timeout(onViewReady);
			}
		}

		/**
		 * Increase number of shown folders asynchronously step-by-step until
		 * they are all visible. This is to avoid script hanging up for too
		 * long on huge collections.
		 */
		function showMore() {
			// show more entries only if the view is not already (being) deactivated
			if ($scope.$parent) {
				$scope.incrementalLoadLimit += INCREMENTAL_LOAD_STEP;
				if ($scope.incrementalLoadLimit < $scope.folders.length) {
					$timeout(showMore);
				} else {
					$timeout(onViewReady);
				}
			}
		}

		function onViewReady() {
			updateHighlight(playQueueService.getCurrentPlaylistId());
			$rootScope.$emit('viewActivated', THIS_VIEW_ID);
		}

		$rootScope.subscribe('foldersLayoutChanged', $scope, makeContentVisible);
	}
]);
