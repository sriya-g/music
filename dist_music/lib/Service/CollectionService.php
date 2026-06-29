<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2018 - 2026
 */

namespace OCA\Music\Service;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\AppFramework\Db\UniqueConstraintViolationException;
use OCA\Music\BusinessLayer\Library;
use OCA\Music\Db\Cache;

use OCP\ICache;

/**
 * Utility to build and cache the monolithic json data describing the whole music library.
 *
 * There has to be a logged-in user to use this class, the userId is injected via the class
 * constructor.
 *
 * This class utilizes two caching mechanism: file-backed \OCP\ICache and database-backed
 * \OCA\Music\Db\Cache. The actual json data is stored in the former and a hash of the data
 * is stored into the latter. The hash is used as a flag indicating that the data is valid.
 * The rationale of this design is that the \OCP\ICache can be used only for the logged-in
 * user, but we must be able to invalidate the cache also in cases when the affected user is
 * not logged in (in FileHooks, ShareHooks, occ commands). On the other hand, depending on
 * the database configuration, the json data may be too large to store it to \OCA\Music\Db\Cache
 * (with tens of thousands of tracks, the size of the json may be more than 10 MB and the
 * DB may be configured with maximum object size of e.g. 1 MB).
 */
class CollectionService {

	public function __construct(
		private Library $library,
		private ICache $fileCache,
		private Cache $dbCache,
		private Logger $logger
	) {
	}

	public function getJson(string $userId) : string {
		$collectionJson = $this->getCachedJson($userId);

		if ($collectionJson === null) {
			$collectionJson = \json_encode($this->library->toCollection($userId));
			try {
				$this->addJsonToCache($collectionJson, $userId);
			} catch (UniqueConstraintViolationException $ex) {
				$this->logger->warning("Race condition: collection.json for user $userId cached twice, ignoring latter.");
			}
		}

		return $collectionJson;
	}

	public function getCachedJsonHash(string $userId) : ?string {
		return $this->dbCache->get($userId, 'collection');
	}

	private function getCachedJson(string $userId) : ?string {
		$json = null;
		$hash = $this->dbCache->get($userId, 'collection');
		if ($hash !== null) {
			$json = $this->fileCache->get('music_collection.json');
			if ($json === null) {
				$this->logger->debug("Inconsistent collection state for user $userId: ".
						"Hash found from DB-backed cache but data not found from the ".
						"file-backed cache. Removing also the hash.");
				$this->dbCache->remove($userId, 'collection');
			}
		}
		return $json;
	}

	private function addJsonToCache(string $json, string $userId) : void {
		$hash = \hash('md5', $json);
		$this->dbCache->add($userId, 'collection', $hash);
		$this->fileCache->set('music_collection.json', $json, 5*365*24*60*60);
	}
}
