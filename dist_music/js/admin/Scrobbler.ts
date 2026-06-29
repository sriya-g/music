/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @copyright Matthew Wells 2026
 */

import { escape } from "lodash";
import { MusicAdminSection } from './AdminSection';

declare var OCP : any;
declare function t(app : string, text : string, vars?: Object) : string;

const SETTINGS_ENDPOINT_PREFIX = 'apps/music/api/settings/scrobbler_credentials/'

class ScrobblerAdmin implements MusicAdminSection {

	#identifier: string;
	#name: string;
	#api_key: string;
	#api_secret: string;

	constructor(data: any) {
		this.#identifier = data.identifier;
		this.#name = data.name;
		this.#api_key = data.api_key;
		this.#api_secret = data.api_secret;
	}

	mount(element: HTMLElement) {
		const formEl = document.createElement('form');
		formEl.classList.add('scrobbler', this.#identifier);
		formEl.name = this.#identifier;

		const keyLabel = escape(t('music', 'API Key'));
		const secretLabel = escape(t('music', 'API Secret'));
		const serviceLabel = escape(this.#name);
		const identifierEsc = escape(this.#identifier);
		formEl.insertAdjacentHTML('afterbegin', `
		<fieldset>
			<legend><h3>${serviceLabel}</h3></legend>
			<div class="field">
				<label for="${identifierEsc}_api_key">${keyLabel}</label>
				<input name="api_key" aria-label="${serviceLabel} ${keyLabel}" id="${identifierEsc}_api_key" type="text" value="${escape(this.#api_key)}" />
			</div>
			<div class="field">
				<input switch type="checkbox" name="show_api_secret" id="${identifierEsc}_show_api_secret"/>
				<label for="${identifierEsc}_show_api_secret">${escape(t('music', 'Show API Secret'))}</label>
				<label for="${identifierEsc}_api_secret">${secretLabel}</label>
				<input name="api_secret" aria-label="${serviceLabel} ${secretLabel}" id="${identifierEsc}_api_secret" type="password" value="${escape(this.#api_secret)}" />
			</div>
			<div class="field">
				<button disabled name="submit_button" type="submit">${escape(t('music', 'Update API credentials for {service}', { service: this.#name }))}</button>
			</div>
		</fieldset>
`);
		const containerEl = document.createElement('div');
		containerEl.classList.add('settings-section');
		containerEl.insertAdjacentElement('afterbegin', formEl);
		element.appendChild(containerEl);
		this.#attachListener(formEl);
	}

	#attachListener(formEl: HTMLFormElement) {
		const apiKeyEl = <HTMLInputElement> formEl.elements.namedItem('api_key');
		const apiSecretEl = <HTMLInputElement> formEl.elements.namedItem('api_secret');
		const submitButton = <HTMLButtonElement> formEl.elements.namedItem('submit_button');
		const showSecretCheck = <HTMLInputElement> formEl.elements.namedItem('show_api_secret');

		formEl.addEventListener('submit', async (e: SubmitEvent) => {
			e.preventDefault();
			if (submitButton.disabled) {
				return false;
			}
			showSecretCheck.checked = false;
			apiSecretEl.type = 'password';

			const url = OC.generateUrl(SETTINGS_ENDPOINT_PREFIX + formEl.name);
			const result = await fetch(url, {
				method: 'POST',
				headers: {
					requesttoken: OC.requestToken,
					'content-type': 'application/json'
				},
				body: JSON.stringify({
					apiKey: apiKeyEl.value,
					apiSecret: apiSecretEl.value
				})
			});

			if (!result.ok) {
				OCA.Music.Dialogs.showErrorNotification((await result.json()).error);
				return;
			}

			OCA.Music.Dialogs.showNotification(t('music', 'Updated {name} credentials!', {name: this.#name}));
			this.#api_key = apiKeyEl.value;
			this.#api_secret = apiSecretEl.value;
			submitButton.disabled = true;
		});

		// disable the submit button when form inputs match initial values
		formEl.addEventListener('input', e => {
			submitButton.disabled = apiKeyEl.value === this.#api_key && apiSecretEl.value === this.#api_secret
			if (e.target === showSecretCheck) {
				apiSecretEl.type = showSecretCheck.checked ? 'text' : 'password';
			}
		});
	}
}

export default class ScrobblersAdmin implements MusicAdminSection {
	#scrobblers: Array<ScrobblerAdmin>;
	constructor() {
		const scrobblers: Array<any> = OCP.InitialState.loadState('music', 'scrobblers', []);
		this.#scrobblers = scrobblers.map(
			scrobbler => new ScrobblerAdmin(scrobbler)
		);
	}

	mount(element: HTMLElement) {
		const root = document.createElement('div');
		root.classList.add('scrobblers');
		root.insertAdjacentHTML('afterbegin', `
		<div class="scrobbler-intro">
			<h2>${t('music', 'Scrobbler Configuration')}</h2>
			<p>${t(
				'music',
				'Configure API connection to begin scrobbling. Check the <a target="_blank" href="{url}">documentation</a> for more details.',
				{url: 'https://github.com/nc-music/music/wiki/Setting-up-Last.fm-connection'}
			)}</p>
		</div>
		`);
		// DOMPurify removes the target attribute
		// t() doesn't give us access to send args to DOMPurify, so we have to add it later
		root.querySelector('a').target = '_blank';
		this.#scrobblers.forEach(scrobbler => scrobbler.mount(root));
		element.appendChild(root);
	}
}
