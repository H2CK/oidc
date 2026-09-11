/**
 * SPDX-FileCopyrightText: 2026 Timill
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// eslint-disable-next-line n/no-extraneous-import
import { createApp } from 'vue'
import DeviceAuthorization from './DeviceAuthorization.vue'

const element = document.getElementById('oidc-device')
if (element) {
	createApp(DeviceAuthorization, {
		mode: element.dataset.mode || 'enter',
		userCode: element.dataset.userCode || '',
		clientName: element.dataset.clientName || '',
		scope: element.dataset.scope || '',
		message: element.dataset.message || '',
	}).mount('#oidc-device')
}
