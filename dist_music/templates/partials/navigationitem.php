<li class="music-navigation-item" ng-class="{ 'active': $parent.currentView == destination }">
	<div class="music-navigation-item-content" ng-click="$parent.navigateTo(destination)"
		ng-class="{current: $parent.playingView == destination, playing: $parent.playing}"
	>
		<div class="play-pause-button svg" ng-hide="playlist && $parent.showEditForm == playlist.id"
			ng-class="icon ? 'icon-' + icon : ''"
			ng-click="$parent.togglePlay(destination, playlist); $event.stopPropagation()"
			title="{{ (($parent.playingView == destination && $parent.playing) ? 'Pause' : 'Play') | translate }}"
		>
			<div class="play-pause"></div>
		</div>
		<span ng-hide="playlist && $parent.showEditForm == playlist.id">{{ text }}</span>
		<div ng-show="playlist && $parent.showEditForm == playlist.id">
			<div class="input-container with-buttons">
				<input type="text" class="edit-list" maxlength="256"
					on-enter="$parent.commitEdit(playlist)" ng-model="playlist.name"/>
			</div>
			<button class="action icon-checkmark"
				ng-class="{ disabled: playlist.name.length == 0 }"
				ng-click="$parent.commitEdit(playlist); $event.stopPropagation()"></button>
		</div>
		<ng-transclude></ng-transclude>
	</div>
</li>