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


angular.module('Music').controller('NavigationController', [
	'$rootScope', '$scope', '$timeout', '$location', 'gettextCatalog',
	'playQueueService', 'playlistService', 'playlistFileService', 'podcastService', 'libraryService',
	function ($rootScope, $scope, $timeout, $location, gettextCatalog,
			playQueueService, playlistService, playlistFileService, podcastService, libraryService) {

		$rootScope.loading = true;

		$scope.newPlaylistName = '';
		$scope.newPlaylistTrackIds = [];
		$scope.radioBusy = false;
		$scope.podcastsBusy = false;

		// holds the state of the editor (visible or not)
		$scope.showCreateForm = false;
		// same as above, but for the search field
		$scope.showSearch = false;
		// same as above, but for the playlist renaming. Holds the number of the playlist, which is currently edited
		$scope.showEditForm = null;

		// Start creating playlist
		$scope.startCreate = function() {
			$scope.showCreateForm = true;
			// Move the focus to the input field. This has to be made asynchronously
			// because the field is not visible yet, it is shown by ng-show binding
			// later during this digest loop.
			$timeout(() => $('#new-list-input').trigger('focus'));
		};

		// Commit creating playlist
		$scope.commitCreate = function() {
			if ($scope.newPlaylistName.length > 0) {
				playlistService.createPlaylist($scope.newPlaylistName, $scope.newPlaylistTrackIds).then((newPlaylist) => {
					$scope.closeCreate();
					$scope.navigateTo(`#playlist/${newPlaylist.id}`);
				});
			}
		};

		$scope.closeCreate = function() {
			$scope.newPlaylistName = '';
			$scope.newPlaylistTrackIds = [];
			$scope.showCreateForm = false;
		};

		$scope.startSearch = function() {
			$scope.showSearch = true;
			// Move the focus to the input field. This has to be made asynchronously
			// because the field is not visible yet, it is shown by ng-show binding
			// later during this digest loop.
			$timeout(() => $('#search-input').trigger('focus'));
			expandCollapsedNavigationPane();
		};

		$scope.clearSearch = function() {
			$('#search-input').val('');
			$('#search-input').trigger('change');
			$scope.showSearch = false;
		};

		$('#search-input').on('blur', function() {
			if (this.value == '') {
				$scope.showSearch = false;
			}
		});

		/**
		 * Catch ctrl+f except when a view not supporting search is active or the search input is already
		 * focused. In the latter case, let the cloud core and/or browser do its default handling.
		 * Note: This event is bound in the *capturing* phase instead of the typical *bubbling* phase.
		 * This is to enable us to execute before the event handler registered by the unified search.
		 * During the bubbling phase, the handlers are executed in the order they are registered and we
		 * can't register our handler before the cloud core.
		 */
		document.addEventListener('keydown', (e) => {
			const noSearchViews = ['#/settings', '#/search'];
			if (!noSearchViews.includes($rootScope.currentView)
				&& !$('#search-input').is(':focus')
				&& !$('#unified-search__input').is(':focus')
				&& e.ctrlKey && e.key === 'f')
			{
				$timeout($scope.startSearch);
				e.preventDefault();
				e.stopPropagation();
				return false;
			}
			return true;
		}, {capture: true});

		$scope.showDetails = function(playlist) {
			$rootScope.$emit('showPlaylistDetails', playlist.id);
			$scope.collapseNavigationPaneOnMobile();
		};

		// Start renaming playlist
		$scope.startEdit = function(playlist) {
			$scope.showEditForm = playlist.id;
			// Move the focus to the input field. This has to be made asynchronously
			// because the field does not exist yet, it is added by ng-if binding
			// later during this digest loop.
			$timeout(() => $('.edit-list').trigger('focus'));
		};

		// Commit renaming of playlist
		$scope.commitEdit = function(playlist) {
			if (playlist.name.length > 0) {
				playlistService.updatePlaylistName(playlist).then(() => {
					$scope.showEditForm = null;
					libraryService.sortPlaylists();
					$scope.navigateTo(`#playlist/${playlist.id}`);
				});
			}
		};

		// Sort playlist
		$scope.sortPlaylist = function(playlist, byProperty) {
			libraryService.sortPlaylist(playlist.id, byProperty);
			handlePlaylistContentChange(playlist);
		};

		// Remove duplicate tracks from a playlist
		$scope.removeDuplicates = function(playlist) {
			let removedTracks = libraryService.removeDuplicatesFromPlaylist(playlist.id);
			let removedCount = removedTracks.length;
			let message = gettextCatalog.getPlural(removedCount,
													'{{ count }} duplicate track was removed',
													'{{ count }} duplicate tracks were removed',
													{ count: removedCount });
			OCA.Music.Dialogs.showNotification(message);

			if (removedCount > 0) {
				handlePlaylistContentChange(playlist);
			}
		};

		// Remove playlist
		$scope.remove = function(playlist) {
			OC.dialogs.confirm(
				gettextCatalog.getString('Are you sure to remove the playlist "{{ name }}"?', { name: playlist.name }),
				gettextCatalog.getString('Remove playlist'),
				function(confirmed) {
					if (confirmed) {
						playlistService.deletePlaylist(playlist).then(() => {
							// if the currently shown view is the playlist being removed, navigate away from it
							if ($rootScope.currentView == '#/playlist/' + playlist.id) {
								$scope.navigateTo('#/');
							}
						});
					}
				},
				true
			);
		};

		// Export playlist to file
		$scope.exportToFile = function(playlist) {
			playlistFileService.exportPlaylist(playlist);
		};

		// Import playlist contents from a file
		$scope.importFromFile = function(playlist = null) {
			playlistFileService.importPlaylist(playlist).then(pl => {
				$scope.navigateTo(`#playlist/${pl.id}`);
			});
		};

		// Export radio stations to a file
		$scope.exportRadioToFile = function() {
			playlistFileService.exportRadio().then(
				() => $scope.radioBusy = false, // success
				() => $scope.radioBusy = false, // failure
				(state) => { // notification about state change
					if (state == 'started') {
						$scope.radioBusy = true;
					} else if (state == 'stopped') {
						$scope.radioBusy = false;
					}
				}
			);
		};

		// Import radio stations from a playlist file
		$scope.importFromFileToRadio = function() {
			playlistFileService.importRadio().then(
				() => $scope.radioBusy = false, // success
				() => $scope.radioBusy = false, // failure
				() => $scope.radioBusy = true   // notification about import actually starting
			);
		};

		$scope.addRadio = function() {
			$scope.showRadioStationDetails(null);
		};

		$scope.addPodcast = function() {
			podcastService.showAddPodcastDialog().then(
				() => $scope.podcastsBusy = false, // success
				() => $scope.podcastsBusy = false, // failure
				() => $scope.podcastsBusy = true,  // started
			);
		};

		$scope.exportPodcastsToFile = function(event) {
			if ($scope.anyPodcastChannels()) {
				podcastService.exportToFile().then(
					() => $scope.podcastsBusy = false, // success
					() => $scope.podcastsBusy = false, // failure
					(state) => { // notification about state change
						if (state == 'started') {
							$scope.podcastsBusy = true;
						} else if (state == 'stopped') {
							$scope.podcastsBusy = false;
						}
					}
				);
			} else {
				event.stopPropagation();
			}
		};

		$scope.importPodcastsFromFile = function() {
			podcastService.importFromFile().then(
				() => $scope.podcastsBusy = false, // success
				() => $scope.podcastsBusy = false, // failure
				() => $scope.podcastsBusy = true   // notification about import actually starting
			);
		};

		$scope.reloadPodcasts = function(event) {
			if ($scope.anyPodcastChannels()) {
				$scope.podcastsBusy = true;
				podcastService.reloadAllPodcasts().then(() => $scope.podcastsBusy = false);
			} else {
				event.stopPropagation();
			}
		};

		$scope.anyPodcastChannels = function() {
			return libraryService.getPodcastChannelsCount() > 0;
		};

		$scope.reloadSmartListView = function() {
			$scope.reloadSmartList();

			// also navigate to the Smart Playlist view if not already open
			$scope.navigateTo('#smartlist');
		};

		$scope.saveSmartList = function() {
			const smartlist = libraryService.getSmartList();
			playlistService.createPlaylist(
				gettextCatalog.getString('Generated {{ datetime }}', { datetime: OCA.Music.Utils.formatDateTime(smartlist.created) }),
				_.map(smartlist.tracks, 'track.id'),
				gettextCatalog.getString('Used filters: {{ params }}', { params: angular.toJson(_.omitBy(smartlist.params, _.isNil), 2) })
			).then((newPlaylist) => {
				$scope.navigateTo(`#playlist/${newPlaylist.id}`);
			});
		};

		// Play/pause playlist
		$scope.togglePlay = function(destination, playlist) {
			if ($rootScope.playingView == destination) {
				playQueueService.publish('togglePlayback');
			}
			else {
				let play = function(id, tracks) {
					if (tracks && tracks.length) {
						playQueueService.setPlaylist(id, tracks);
						playQueueService.publish('play', destination);
					}
				};

				if (destination == '#') {
					play('albums', libraryService.getTracksInAlbumOrder());
				} else if (destination == '#/alltracks') {
					play('alltracks', libraryService.getTracksInAlphaOrder());
				} else if (destination == '#/smartlist') {
					play('smartlist', libraryService.getSmartList().tracks);
				} else if (destination == '#/folders') {
					$scope.$parent.loadFoldersAndThen(function() {
						play('folders', libraryService.getTracksInFolderOrder(!$scope.foldersFlatLayout));
					});
				} else if (destination == '#/genres') {
					play('genres', libraryService.getTracksInGenreOrder());
				} else if (destination === '#/radio') {
					play('radio', libraryService.getAllRadioStations());
				} else if (destination === '#/podcasts') {
					play('podcasts', _.map(libraryService.getAllPodcastEpisodes(), (episode) => ({track: episode})));
				} else {
					play('playlist-' + playlist.id, playlist.tracks);
				}
			}
		};

		// Add track to the playlist
		$scope.addTrack = function(playlist, songId) {
			addTracks(playlist, [songId]);
		};

		// Add all tracks on an album to the playlist
		$scope.addAlbum = function(playlist, albumId) {
			addTracks(playlist, trackIdsFromAlbum(albumId));
		};

		// Add all tracks on all albums by an artist to the playlist
		$scope.addAlbumArtist = function(playlist, artistId) {
			addTracks(playlist, trackIdsFromAlbumArtist(artistId));
		};

		// All all tracks somehow connected to an artist to the playlist
		$scope.addArtist = function(playlist, artistId) {
			addTracks(playlist, trackIdsFromArtist(artistId));
		};

		// Add all tracks in a folder to the playlist
		$scope.addFolder = function(playlist, folderId) {
			addTracks(playlist, trackIdsFromFolder(folderId));
		};

		// Add all tracks of the genre to the playlist
		$scope.addGenre = function(playlist, genreId) {
			addTracks(playlist, trackIdsFromGenre(genreId));
		};

		// Add all tracks of another playlist to the playlist
		$scope.addPlaylist = function(playlist, anotherListId) {
			addTracks(playlist, trackIdsFromPlaylist(anotherListId));
		};

		// An item dragged and dropped on a navigation bar playlist item
		$scope.dropOnPlaylist = function(droppedItem, playlist) {
			if ('track' in droppedItem) {
				$scope.addTrack(playlist, droppedItem.track);
			} else if ('tracks' in droppedItem) {
				addTracks(playlist, droppedItem.tracks);
			} else if ('album' in droppedItem) {
				$scope.addAlbum(playlist, droppedItem.album);
			} else if ('albumartist' in droppedItem) {
				$scope.addAlbumArtist(playlist, droppedItem.albumartist);
			} else if ('artist' in droppedItem) {
				$scope.addArtist(playlist, droppedItem.artist);
			} else if ('folder' in droppedItem) {
				$scope.addFolder(playlist, droppedItem.folder);
			} else if ('genre' in droppedItem) {
				$scope.addGenre(playlist, droppedItem.genre);
			} else if ('playlist' in droppedItem) {
				$scope.addPlaylist(playlist, droppedItem.playlist);
			} else {
				console.error('Unknown entity dropped on playlist');
			}

			// 50 ms haptic feedback for touch devices
			if ('vibrate' in navigator) {
				navigator.vibrate(50);
			}
		};

		$scope.allowDrop = function(playlist, draggable) {
			// Don't allow dragging a track from a playlist back to the same playlist
			let isFromPlaylist = ('srcIndex' in draggable);
			let targetIsCurrentPlaylist = ($rootScope.currentView == '#/playlist/' + playlist.id);
			return !isFromPlaylist || !targetIsCurrentPlaylist;
		};

		// Dragging an entity over the navigation toggle pops the navigation pane open.
		// Subsequently, ending the drag closes the navigation pane.
		// It occasionally happens (at least on Chrome) that the navigation toggle is not yet
		// present when this controller is initialized. In those cases, the related logic
		// is injected a bit later. See https://github.com/owncloud/music/issues/1137.
		OCA.Music.Utils.executeOnceRefAvailable(
			() => document.getElementById('app-navigation-toggle'),
			(navToggle) => {
				let navOpenedByDrag = false;
				navToggle.addEventListener('dragenter', () => {
					if (!navOpenedByDrag) {
						navOpenedByDrag = true;
						expandCollapsedNavigationPane();
					}
				});
				document.addEventListener('dragend', () => {
					if (navOpenedByDrag) { 
						navOpenedByDrag = false;
						$scope.collapseNavigationPaneOnMobile();
					}
				});
			}
		);

		function expandCollapsedNavigationPane() {
			const $navToggle = $('#app-navigation-toggle');
			if (!$scope.mobileNavigationPaneExpanded() && $navToggle.is(':visible')) {
				$timeout(() => $navToggle.trigger('click'));
			}
		}

		function trackIdsFromAlbum(albumId) {
			let album = libraryService.getAlbum(albumId);
			return _.map(album.tracks, 'id');
		}

		function trackIdsFromAlbumArtist(artistId) {
			let artist = libraryService.getArtist(artistId);
			return _(artist.albums).map('id').map(trackIdsFromAlbum).flatten().value();
		}

		function trackIdsFromArtist(artistId) {
			let tracks = libraryService.findTracksInvolvingArtist(artistId);
			return _.map(tracks, 'id');
		}

		function trackIdsFromFolder(folderId) {
			let folder = libraryService.getFolder(folderId);
			let tracks = libraryService.getFolderTracks(folder, !$scope.foldersFlatLayout);
			return _.map(tracks, 'track.id');
		}

		function trackIdsFromGenre(genreId) {
			let genre = libraryService.getGenre(genreId);
			return _.map(genre.tracks, 'track.id');
		}

		function trackIdsFromPlaylist(playlistId) {
			let playlist = libraryService.getPlaylist(playlistId);
			return _.map(playlist.tracks, 'track.id');
		}

		function addTracks(playlist, trackIds) {
			if (playlist === null) {
				// tracks dropped on the "+ New Playlist" item, start creating a new list
				$scope.newPlaylistTrackIds = $scope.newPlaylistTrackIds.concat(trackIds);
				$scope.startCreate();
			}
			else {
				playlistService.addTracksToPlaylist(playlist, trackIds);
			}
		}

		function handlePlaylistContentChange(playlist) {
			// Update the currently playing list if necessary
			if ($rootScope.playingView == '#/playlist/' + playlist.id) {
				let playingIndex = _.findIndex(playlist.tracks, { track: $scope.currentTrack });
				playQueueService.onPlaylistModified(playlist.tracks, playingIndex);
			}

			playlistService.updatePlaylistTracks(playlist);
		}

		$rootScope.$on('viewActivated', function() {
			// start playing the current view if the 'autoplay' argument is present in the URL and has a truthy value
			if (OCA.Music.Utils.parseBoolean($location.search().autoplay)) {
				if (!$rootScope.playing) {
					let playlist = null;
					if ($rootScope.currentView.startsWith('#/playlist/')) {
						let id = _.last($rootScope.currentView.split('/'));
						playlist = libraryService.getPlaylist(id);
					}
					$scope.togglePlay($rootScope.currentView, playlist);
				}
			}
			// ensure that the link to the current view is visible in the navigation pane, 
			// in case there are so many playlists that the navigation pane is scrollable
			$timeout(() => {
				const navPaneContent = $('#app-navigation ul');
				const navItem = navPaneContent.find('.music-navigation-item.active');
				if (navItem.length) { // navItem is empty when Settings view activated
					const navItemTop = navItem.offset().top - $('#header').height();
					const navItemBottom = navItemTop + navItem.height();
					if (navItemTop < 0 || navItemBottom > navPaneContent.innerHeight()) {
						navPaneContent.scrollToElement(navItem, 0, 300);
					}
				}
			});
		});
	}
]);
