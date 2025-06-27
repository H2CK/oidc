/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// eslint-disable-next-line n/no-extraneous-import
import { createApp } from 'vue'
import App from './App.vue'
import { loadState } from '@nextcloud/initial-state'

const clients = loadState('oidc', 'clients')
const expireTime = loadState('oidc', 'expireTime')
const refreshExpireTime = loadState('oidc', 'refreshExpireTime')
const publicKey = loadState('oidc', 'publicKey')
const groups = loadState('oidc', 'groups')
const logoutRedirectUris = loadState('oidc', 'logoutRedirectUris')
const overwriteEmailVerified = loadState('oidc', 'overwriteEmailVerified')
const dynamicClientRegistration = loadState('oidc', 'dynamicClientRegistration')
const allowUserSettings = loadState('oidc', 'allowUserSettings')
const restrictUserInformation = loadState('oidc', 'restrictUserInformation')

const app = createApp(App, {
	clients,
	expireTime,
	refreshExpireTime,
	publicKey,
	groups,
	logoutRedirectUris,
	overwriteEmailVerified,
	dynamicClientRegistration,
	allowUserSettings,
	restrictUserInformation,
})

app.config.globalProperties.$OC = window.OC

app.mount('#oidc')
