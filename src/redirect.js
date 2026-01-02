/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// eslint-disable-next-line n/no-extraneous-import
import { createApp } from 'vue'
import App from './Redirect.vue'

const app = createApp(App)

app.config.globalProperties.$OC = window.OC

app.mount('#oidc-redirect')
