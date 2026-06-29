<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2024 - 2026
 */

namespace OCA\Music\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

use OCA\Music\AppFramework\BusinessLayer\BusinessLayer;
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\BusinessLayer\ArtistBusinessLayer;
use OCA\Music\BusinessLayer\PlaylistBusinessLayer;
use OCA\Music\BusinessLayer\PodcastChannelBusinessLayer;
use OCA\Music\BusinessLayer\PodcastEpisodeBusinessLayer;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Http\ErrorResponse;

class FavoritesController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private AlbumBusinessLayer $albumBusinessLayer,
		private ArtistBusinessLayer $artistBusinessLayer,
		private PlaylistBusinessLayer $playlistBusinessLayer,
		private PodcastChannelBusinessLayer $podcastChannelBusinessLayer,
		private PodcastEpisodeBusinessLayer $podcastEpisodeBusinessLayer,
		private TrackBusinessLayer $trackBusinessLayer,
		private string $userId
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function favorites() : JSONResponse {
		return new JSONResponse([
			'tracks' => $this->trackBusinessLayer->findAllStarredIds($this->userId),
			'albums' => $this->albumBusinessLayer->findAllStarredIds($this->userId),
			'artists' => $this->artistBusinessLayer->findAllStarredIds($this->userId),
			'playlists' => $this->playlistBusinessLayer->findAllStarredIds($this->userId),
			'podcast_channels' => $this->podcastChannelBusinessLayer->findAllStarredIds($this->userId),
			'podcast_episodes' => $this->podcastEpisodeBusinessLayer->findAllStarredIds($this->userId),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setFavoriteTrack(int $id, string|int|bool|null $status) : JSONResponse {
		return $this->setFavorite($this->trackBusinessLayer, $id, $status);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setFavoriteAlbum(int $id, string|int|bool|null $status) : JSONResponse {
		return $this->setFavorite($this->albumBusinessLayer, $id, $status);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setFavoriteArtist(int $id, string|int|bool|null $status) : JSONResponse {
		return $this->setFavorite($this->artistBusinessLayer, $id, $status);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setFavoritePlaylist(int $id, string|int|bool|null $status) : JSONResponse {
		return $this->setFavorite($this->playlistBusinessLayer, $id, $status);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setFavoriteChannel(int $id, string|int|bool|null $status) : JSONResponse {
		return $this->setFavorite($this->podcastChannelBusinessLayer, $id, $status);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setFavoriteEpisode(int $id, string|int|bool|null $status) : JSONResponse {
		return $this->setFavorite($this->podcastEpisodeBusinessLayer, $id, $status);
	}

	/**
	 * @phpstan-param BusinessLayer<*> $businessLayer
	 */
	private function setFavorite(BusinessLayer $businessLayer, int $id, string|int|bool|null $status) : JSONResponse {
		if ($status === null) {
			return new ErrorResponse(Http::STATUS_BAD_REQUEST, "argument 'status' is required");
		} else {
			$status = \filter_var($status, FILTER_VALIDATE_BOOLEAN);
			if ($status) {
				$businessLayer->setStarred([$id], $this->userId);
			} else {
				$businessLayer->unsetStarred([$id], $this->userId);
			}
			return new JSONResponse(['favorite' => ($status != 0)]);
		}
	}
}
