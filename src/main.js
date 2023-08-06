/**
 * @copyright Copyright (c) 2022-2023 Thorsten Jagel <dev@jagel.net>
 *
 * @author Thorsten Jagel <dev@jagel.net>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

import Vue from 'vue'
import App from './App.vue'
import { loadState } from '@nextcloud/initial-state'

Vue.prototype.t = t
Vue.prototype.OC = OC

const clients = loadState('oidc', 'clients')
const expireTime = loadState('oidc', 'expireTime')
const publicKey = loadState('oidc', 'publicKey')
const groups = loadState('oidc', 'groups')
const logoutRedirectUris = loadState('oidc', 'logoutRedirectUris')
const integrateAvatar = loadState('oidc', 'integrateAvatar')

const View = Vue.extend(App)
const oidc = new View({
	propsData: {
		clients,
		expireTime,
		publicKey,
		groups,
		logoutRedirectUris,
		integrateAvatar,
	},
})
oidc.$mount('#oidc')
