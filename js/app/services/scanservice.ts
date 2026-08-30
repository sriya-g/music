/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2026
 */

import * as ng from 'angular';
import { IService } from 'restangular';
import { gettextCatalog } from 'angular-gettext';

const FILES_TO_SCAN_PER_STEP = 20;

export interface IScanService {
	scan(fileIds : number[]) : ng.IPromise<void>;
	stopScan() : void;
	isScanning() : boolean;
}

ng.module('Music').service('scanService', ['$timeout', '$q', 'Restangular',
function($timeout : ng.ITimeoutService, $q : ng.IQService, Restangular : IService) {

	class ScanService implements IScanService {

		#fileIdsToScan : number[] = [];
		#scannedCount : number = 0;
		#deferred : ng.IDeferred<void>|null = null;

		scan(fileIds : number[]) : ng.IPromise<void> {
			if (this.#deferred === null) {
				this.#deferred = $q.defer<void>();

				this.#fileIdsToScan = fileIds;
				this.#scannedCount = 0;

				// launch asynchronously to allow the caller the get also the first progress notification
				$timeout(() => this.#processNextScanStep());
			}

			return this.#deferred.promise;
		}

		stopScan() : void {
			if (this.#deferred !== null) {
				this.#deferred.reject('canceled');
				this.#deferred = null;
			}
		}

		isScanning() : boolean {
			return (this.#deferred !== null);
		}

		#processNextScanStep() : void {
			const totalCount = this.#fileIdsToScan.length;
			const sliceEnd = Math.min(this.#scannedCount + FILES_TO_SCAN_PER_STEP, totalCount);
			const filesForStep = this.#fileIdsToScan.slice(this.#scannedCount, sliceEnd);
			const params = {
				files: filesForStep.join(','),
				finalize: (filesForStep.length === 0)
			};

			this.#deferred!.notify({
				scannedCount: this.#scannedCount,
				totalCount: totalCount
			});

			Restangular.all('scan').post(params).then((_result) => {
				// Ignore the results if scanning has been cancelled while we were waiting for the result.
				if (this.isScanning()) {
					this.#scannedCount = sliceEnd;

					if (!params.finalize) {
						this.#processNextScanStep();
					} else if (this.#deferred !== null) {
						this.#deferred.resolve();
						this.#deferred = null;
					}
				}
			}).catch((error : any) => {
				if (this.#deferred !== null) {
					this.#deferred.reject(error);
					this.#deferred = null;
				}
			});
		}
	}

	return new ScanService();
}]);