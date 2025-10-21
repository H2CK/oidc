/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import Vue from 'vue'
import Consent from './Consent.vue'

// Get data attributes from the container element
const consentEl = document.getElementById('oidc-consent')

if (consentEl) {
	const View = Vue.extend(Consent)
	new View({
		propsData: {
			clientName: consentEl.dataset.clientName || 'Unknown Application',
			requestedScopes: consentEl.dataset.scopes || 'openid',
			clientId: consentEl.dataset.clientId || '',
		},
	}).$mount('#oidc-consent')
}
