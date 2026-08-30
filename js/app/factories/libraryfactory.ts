/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright 2026 Pauli Järvinen
 *
 */

import * as ng from 'angular';
import { MusicRootScope } from 'app/config/musicrootscope';
import { IService } from 'restangular';
import { gettextCatalog } from 'angular-gettext';
import { Artist, Folder, Genre, LibraryService, Playlist, PlaylistEntry, PodcastChannel, RadioStation } from 'app/services/libraryservice';
import { IScanService } from 'app/services/scanservice';

type SmartListParams = Record<string, string|number|boolean|null>;

ng.module('Music').factory('libraryFactory', ['Restangular', 'gettextCatalog', '$rootScope', '$q', 'libraryService', 'scanService',
function (Restangular : IService, gettextCatalog : gettextCatalog, $rootScope : MusicRootScope, $q : ng.IQService, libraryService : LibraryService, scanService : IScanService) {

	let collectionPromise : ng.IPromise<Artist[]> | null = null;
	let deferredCollectionAbort : ng.IDeferred<void>|null = null;
	let rootFolderPromise : ng.IPromise<Folder> | null = null;
	let playlistsPromise : ng.IPromise<Playlist[]> | null = null;
	let smartListPromise : ng.IPromise<Playlist> | null = null;
	let smartListParams : SmartListParams | null = null;
	let genresPromise : ng.IPromise<Genre[]> | null = null;
	let radioStationsPromise : ng.IPromise<PlaylistEntry<RadioStation>[]> | null = null;
	let podcastChannelsPromise : ng.IPromise<PodcastChannel[]> | null = null;

	function resetCollection() : void {
		if (deferredCollectionAbort !== null) {
			deferredCollectionAbort.resolve();
			deferredCollectionAbort = null;
		}

		collectionPromise = null;
		rootFolderPromise = null;
		playlistsPromise = null;
		smartListPromise = null;
		smartListParams = null;
		genresPromise = null;
		libraryService.setCollection([]);
		libraryService.setFolders(null);
		libraryService.setPlaylists([]);
		libraryService.setSmartList(null);
		libraryService.setGenres(null);
	}

	function publish(eventName : string) : void {
		$rootScope.$emit('libraryFactory:' + eventName);
	}

	// Factory API
	return {
		subscribe(eventName : string, listenerScope : ng.IScope, listener : (event: ng.IAngularEvent, ...args: any[]) => any) : void {
			var handle = $rootScope.$on('libraryFactory:' + eventName, listener);
			listenerScope.$on('$destroy', handle);
		},

		getCollection() : ng.IPromise<Artist[]> {
			if (!collectionPromise) {
				deferredCollectionAbort = $q.defer<void>();
				const config = {timeout: deferredCollectionAbort.promise};

				// During an ongoing scanning, using the `prepare_collection` would be pointless and
				// waste of time and resources. By the time we would call GET `collection`, the server
				// would likely have ditched the prepared collection anyway. And even if it hasn't, it
				// would anyway be the next time we call `prepare_collection` and the client-side-cached
				// collection could never by used.
				const fetchCollectionPromise = scanService.isScanning()
					? Restangular.all('collection').withHttpConfig(config).getList()
					: Restangular.all('prepare_collection').withHttpConfig(config).post({}).then((reply) => {
						return Restangular.all('collection').withHttpConfig(config).getList({ hash: reply.hash });
					});

				collectionPromise = fetchCollectionPromise.then((artists) => {
					libraryService.setCollection(artists);
					publish('collectionLoaded');
					return libraryService.getCollection();
				}).catch((errorResponse) : Artist[] => {
					if (errorResponse.status === -1) {
						console.log('collection loading was aborted by user action');
					} else {
						const reason = {
							401: gettextCatalog.getString('Not logged in'),
							403: gettextCatalog.getString('Access denied'),
							500: gettextCatalog.getString('Internal server error'),
							504: gettextCatalog.getString('Timeout'),
						}[errorResponse.status as string] ?? errorResponse.status;

						const errMsg = gettextCatalog.getString('Failed to load the collection:');
						OCA.Music.Dialogs.showNotification(errMsg + ' ' + reason);
					}
					return [];
				}).finally(() => deferredCollectionAbort = null);
				publish('collectionUpdating');
			}
			return collectionPromise;
		},

		reloadCollection() : ng.IPromise<Artist[]> {
			resetCollection();

			// Playlists, genres, folders, and smart list all depend on the collection and
			// they are all loaded in parallel
			this.getPlaylists();
			this.getGenres();
			this.getRootFolder();
			this.getSmartList();

			return this.getCollection();
		},

		getAllArtists() : ng.IPromise<Artist[]> {
			return this.getCollection().then(() => libraryService.getAllArtists());
		},

		getRootFolder() : ng.IPromise<Folder> {
			if (!rootFolderPromise) {
				const fetchFoldersPromise = Restangular.all('folders').getList();

				// the collection has to be set on the libraryService before the folders can be set
				rootFolderPromise = $q.all([this.getCollection(), fetchFoldersPromise]).then(([_collection, folders]) => {
					libraryService.setFolders(folders);
					return libraryService.getRootFolder();
				});
			}
			return rootFolderPromise;
		},

		getGenres() : ng.IPromise<Genre[]> {
			if (!genresPromise) {
				const fetchGenresPromise = Restangular.all('genres').getList();

				// the collection has to be set on the libraryService before the genres can be set
				genresPromise = $q.all([this.getCollection(), fetchGenresPromise]).then(([_collection, genres]) => {
					libraryService.setGenres(genres);
					return libraryService.getAllGenres();
				});
			}
			return genresPromise;
		},

		getPlaylists() : ng.IPromise<Playlist[]> {
			if (!playlistsPromise) {
				const fetchPlaylistsPromise = Restangular.all('playlists').getList({type: 'music-app'});

				// the collection has to be set on the libraryService before the playlists can be set
				playlistsPromise = $q.all([this.getCollection(), fetchPlaylistsPromise]).then(([_collection, playlists]) => {
					libraryService.setPlaylists(playlists);
					return libraryService.getAllPlaylists();
				});
			}
			return playlistsPromise;
		},

		getSmartList() : ng.IPromise<Playlist> {
			if (!smartListPromise) {
				const genArgs = smartListParams ?? { useLatestParams: true };

				const fetchSmartListPromise = Restangular.one('playlists/generate').get(genArgs);

				smartListPromise = $q.all([this.getCollection(), fetchSmartListPromise]).then(([_collection, smartList]) => {
					libraryService.setSmartList(smartList);
					smartListParams = smartList.params;
					publish('smartListLoaded');
					return libraryService.getSmartList()!;
				});
			}
			return smartListPromise;
		},

		reloadSmartList(params? : SmartListParams) : ng.IPromise<Playlist> {
			if (params) {
				smartListParams = params;
			}
			libraryService.setSmartList(null);
			smartListPromise = null;
			return this.getSmartList();
		},

		getSmartListParams() : ng.IPromise<SmartListParams> {
			return this.getSmartList().then(() => smartListParams!);
		},

		getRadioStations() : ng.IPromise<PlaylistEntry<RadioStation>[]> {
			if (!radioStationsPromise) {
				radioStationsPromise = Restangular.all('radio').getList().then((radioStations) => {
					libraryService.setRadioStations(radioStations);
					return libraryService.getAllRadioStations();
				});
			}
			return radioStationsPromise;
		},

		getPodcastChannels() : ng.IPromise<PodcastChannel[]> {
			if (!podcastChannelsPromise) {
				podcastChannelsPromise = Restangular.all('podcasts').getList().then((channels) => {
					libraryService.setPodcasts(channels);
					return libraryService.getAllPodcastChannels();
				});
			}
			return podcastChannelsPromise;
		},

		updateFavorites() : ng.IPromise<void> {
			const fetchFavoritesPromise = Restangular.one('favorites').get();

			// collection, playlists, and podcasts have to be loaded before favorites can be set, because favorites can contain items from all of those
			return $q.all([this.getCollection(), this.getPlaylists(), this.getPodcastChannels(), fetchFavoritesPromise]).then(
				([_collection, _playlists, _podcastChannels, favorites]) => libraryService.setFavorites(favorites)
			);
		},

		resetRadioStations() : void {
			radioStationsPromise = null;
			libraryService.setRadioStations([]);
		},

		resetPodcasts() : void {
			podcastChannelsPromise = null;
			libraryService.setPodcasts([]);
		}
	};
}]);
