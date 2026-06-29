<?php
use OCA\Music\Utility\HtmlUtil;

HtmlUtil::printNgTemplate('navigationitem');
?>

<div id="app-navigation" ng-controller="NavigationController">
	<ul>
		<li navigation-item text="'Albums' | translate" destination="'#'" title="{{ albumCountText() }}" icon="'album'">
			<popup-menu>
				<popup-menu-item ng-click="toggleAlbumsCompactLayout(false);" platform-icon="albumsCompactLayout ? 'radio-button' : 'radio-button-checked'">{{ 'Normal layout' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="toggleAlbumsCompactLayout(true);" platform-icon="albumsCompactLayout ? 'radio-button-checked' : 'radio-button'">{{ 'Compact layout' | translate }}</popup-menu-item>
			</popup-menu>
		</li>
		<li navigation-item text="'Folders' | translate" destination="'#/folders'" title="{{ folderCountText() }}" icon="'folder-nav'">
			<popup-menu>
				<popup-menu-item ng-click="toggleFoldersFlatLayout(false);" platform-icon="foldersFlatLayout ? 'radio-button' : 'radio-button-checked'">{{ 'Tree layout' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="toggleFoldersFlatLayout(true);" platform-icon="foldersFlatLayout ? 'radio-button-checked' : 'radio-button'">{{ 'Flat layout' | translate }}</popup-menu-item>
			</popup-menu>
		</li>
		<li navigation-item text="'Genres' | translate" destination="'#/genres'" title="{{ genresCountText() }}" icon="'audiotrack'"></li>
		<li navigation-item text="'All tracks' | translate" destination="'#/alltracks'" title="{{ trackCountText() }}" icon="'library-music'"></li>
		<li class="app-navigation-separator"></li>
		<li navigation-item text="'Internet radio' | translate" destination="'#/radio'" title="{{ radioCountText() }}" icon="'radio'">
			<popup-menu busy="radioBusy">
				<popup-menu-item ng-click="showRadioHint();" platform-icon="'details'">{{ 'Getting started' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="importFromFileToRadio();" icon="'from-file'">{{ 'Import from file' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="exportRadioToFile();" icon="'to-file'">{{ 'Export to file' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="addRadio();" platform-icon="'add'">{{ 'Add manually' | translate }}</popup-menu-item>
			</popup-menu>
		</li>
		<li navigation-item text="'Podcasts' | translate" destination="'#/podcasts'" title="{{ podcastsCountText() }}" icon="'podcast'">
			<popup-menu busy="podcastsBusy">
				<popup-menu-item ng-click="addPodcast();" platform-icon="'add'">{{ 'Add from RSS feed' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="importPodcastsFromFile();" icon="'from-file'">{{ 'Import from file' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="exportPodcastsToFile($event);" icon="'to-file'" ng-class="{ disabled: !anyPodcastChannels() }">{{ 'Export to file' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="reloadPodcasts($event);" icon="'reload'" ng-class="{ disabled: !anyPodcastChannels() }">{{ 'Reload channels' | translate }}</popup-menu-item>
			</popup-menu>
		</li>
		<li class="app-navigation-separator"></li>
		<li navigation-item text="'Smart playlist' | translate" destination="'#/smartlist'" title="{{ smartListTrackCountText() }}" icon="'smart-playlist'">
			<popup-menu>
				<popup-menu-item ng-click="reloadSmartListView();" icon="'reload'">{{ 'Reload' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="showSmartListFilters();" icon="'filter'">{{ 'Filters' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="saveSmartList();" icon="'playlist'">{{ 'Save playlist' | translate }}</popup-menu-item>
			</popup-menu>
		</li>
		<li class="music-navigation-item" ui-on-drop="dropOnPlaylist($data, null)" drag-hover-class="drag-hover">
			<div id="new-playlist" class="music-navigation-item-content">
				<div class="icon-add" ng-click="startCreate()" ng-if="!newPlaylistTrackIds.length"></div>
				<div class="track-count-badge" ng-if="newPlaylistTrackIds.length">{{ newPlaylistTrackIds.length }}</div>
				<div class="label" ng-click="startCreate()" ng-hide="showCreateForm" translate>New Playlist</div>
				<popup-menu ng-hide="showCreateForm">
					<popup-menu-item ng-click="importFromFile();" icon="'from-file'">{{ 'Import from file' | translate }}</popup-menu-item>
				</popup-menu>
				<div class="input-container with-buttons" ng-show="showCreateForm">
					<input id="new-list-input" type="text" maxlength="256"
						placeholder="{{ 'New Playlist' | translate }}" ng-model="newPlaylistName"
						on-enter="commitCreate()" on-esc="closeCreate()" />
				</div>
				<div class="actions" ng-show="showCreateForm">
					<button class="action icon-checkmark"
						ng-class="{ disabled: newPlaylistName.length == 0}" ng-click="commitCreate()"></button>
					<button class="action icon-close" ng-click="closeCreate()"></button>
				</div>
			</div>
		</li>
		<li navigation-item
			playlist="playlist" text="playlist.name" destination="'#/playlist/' + playlist.id"
			ng-repeat="playlist in playlists"
			ui-on-drop="dropOnPlaylist($data, playlist)"
			drop-validate="allowDrop(playlist, $data)"
			drag-hover-class="drag-hover"
			title="{{ playlist.name + ' (' + trackCountText(playlist) + ')' }}"
			icon="'playlist'"
		>
			<popup-menu ng-init="subMenuShown = false" ng-show="showEditForm == null" busy="playlist.busy">
				<popup-menu-item ng-click="showDetails(playlist);" platform-icon="'details'">{{ 'Details' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="startEdit(playlist);" platform-icon="'rename'">{{ 'Rename' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="importFromFile(playlist);" icon="'from-file'">{{ 'Import from file' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="exportToFile(playlist);" icon="'to-file'">{{ 'Export to file' | translate }}</popup-menu-item>
				<popup-sub-menu icon="'sort-by-alpha'" text="'Sort…' | translate">
					<popup-menu-item ng-click="sortPlaylist(playlist, 'track');">{{ 'by title' | translate }}</popup-menu-item>
					<popup-menu-item ng-click="sortPlaylist(playlist, 'artist');">{{ 'by artist' | translate }}</popup-menu-item>
					<popup-menu-item ng-click="sortPlaylist(playlist, 'album');">{{ 'by album' | translate }}</popup-menu-item>
				</popup-sub-menu>
				<popup-menu-item ng-click="removeDuplicates(playlist);" platform-icon="'close'">{{ 'Remove duplicates' | translate }}</popup-menu-item>
				<popup-menu-item ng-click="remove(playlist);" platform-icon="'delete'">{{ 'Delete' | translate }}</popup-menu-item>
			</popup-menu>
		</li>
		<li id="music-nav-search" class="docked-navigation-item music-navigation-item" ng-class="{active: currentView=='#/search'}"
			title="{{ showSearch ? null : '[CTRL+F]' }}">
			<div class="music-navigation-item-content">
				<div class="icon-search" ng-click="startSearch()"></div>
				<div class="label" ng-click="startSearch()" ng-hide="showSearch" translate>Search</div>
				<div class="input-container" ng-show="showSearch">
					<input id="search-input" type="text" placeholder="{{ 'Search' | translate }}"
						ng-model="searchInput" enterkeyhint="search"
						on-enter="collapseNavigationPaneOnMobile()"
						on-esc="clearSearch(); collapseNavigationPaneOnMobile()" />
					<button id="clear-search" class="icon-close" ng-click="clearSearch()"></button>
				</div>
				<popup-menu>
					<popup-menu-item ng-click="navigateTo('#/search');" platform-icon="'search'">{{ 'Advanced search' | translate }}</popup-menu-item>
				</popup-menu>
			</div>
		</li>
		<li id="music-nav-settings" class="docked-navigation-item" ng-class="{active: currentView=='#/settings'}">
			<a class="music-navigation-item-content" ng-click="navigateTo('#/settings');">
				<span class="icon-settings-dark"></span>
				<span class="label" translate>Settings</span>
			</a>
		</li>
	</ul>
</div>