/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// eslint-disable-next-line n/no-extraneous-import
import { createApp } from 'vue'
import AppPersonal from './AppPersonal.vue'
import { loadState } from '@nextcloud/initial-state'

const allowUserSettings = loadState('oidc', 'allowUserSettings')
const restrictUserInformation = loadState('oidc', 'restrictUserInformation')

const app = createApp(AppPersonal, {
	allowUserSettings,
	restrictUserInformation,
})

app.config.globalProperties.$OC = window.OC

app.mount('#oidc-personal')
