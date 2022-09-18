<!--
  - @copyright Copyright (c) 2022 Thorsten Jagel <dev@jagel.net>
  -
  - @author Thorsten Jagel <dev@jagel.net>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->
<template>
	<div id="oidc" class="section">
		<h2>{{ t('oidc', 'OpenID Connect clients') }}</h2>
		<p class="settings-hint">
			{{ t('oidc', 'OpenID Connect allows to authenticate at external services with {instanceName} user accounts.', { instanceName: OC.theme.name}) }}
		</p>
		<table v-if="clients.length > 0" class="grid">
			<thead>
				<tr>
					<th id="headerContent" />
					<th id="headerRemove">
&nbsp;
					</th>
				</tr>
			</thead>
			<tbody>
				<OIDCItem v-for="client in clients"
					:key="client.id"
					:client="client"
					@delete="deleteClient" />
			</tbody>
		</table>

		<br>
		<h3>{{ t('oidc', 'Add client') }}</h3>
		<span v-if="newClient.error" class="msg error">{{ newClient.errorMsg }}</span>
		<form @submit.prevent="addClient">
			<input id="name"
				v-model="newClient.name"
				type="text"
				name="name"
				:placeholder="t('oidc', 'Name')">
			<input id="redirectUri"
				v-model="newClient.redirectUri"
				type="url"
				name="redirectUri"
				:placeholder="t('oidc', 'Redirection URI')">
			<select id="signingAlg"
				v-model="newClient.signingAlg">
				<option disabled value="">
					{{ t('oidc', 'Select Signing Algorithm') }}
				</option>
				<option value="RS256">
					RS256
				</option>
				<option value="HS256">
					HS256
				</option>
			</select>
			<select id="type"
				v-model="newClient.type">
				<option disabled value="">
					{{ t('oidc', 'Select Type') }}
				</option>
				<option value="confidential">
					{{ t('oidc', 'Confidential') }}
				</option>
				<option value="public">
					{{ t('oidc', 'Public') }}
				</option>
			</select>
			<input type="submit" class="button" :value="t('oidc', 'Add')">
		</form>

		<br>
		<h3>{{ t('oidc', 'Settings') }}</h3>
		<p>{{ t('oidc', 'Token Expire Time') }}</p>
		<select id="expireTime"
			v-model="expTime"
			:placeholder="t('oidc', 'Token Expire Time')"
			@change="setTokenExpireTime">
			<option disabled value="">
				{{ t('oidc', 'Select Token Expire Time') }}
			</option>
			<option value="300">
				{{ t('oidc', '5 minutes') }}
			</option>
			<option value="600">
				{{ t('oidc', '10 minutes') }}
			</option>
			<option value="900">
				{{ t('oidc', '15 minutes') }}
			</option>
			<option value="1800">
				{{ t('oidc', '30 minutes') }}
			</option>
			<option value="3600">
				{{ t('oidc', '60 minutes') }}
			</option>
		</select>
		<p>{{ t('oidc', 'Public Key') }}</p>
		<code>{{ publicKey }}</code>
		<br>
		<form @submit.prevent="regenerateKeys">
			<input type="submit" class="button" :value="t('oidc', 'Regenerate Keys')">
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import OIDCItem from './components/OIDCItem'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'App',
	components: {
		OIDCItem,
	},
	props: {
		clients: {
			type: Array,
			required: true,
		},
		expireTime: {
			type: String,
			required: true,
		},
		publicKey: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			newClient: {
				name: '',
				redirectUri: '',
				signingAlg: 'RS256',
				type: 'confidential',
				errorMsg: '',
				error: false,
			},
			expTime: this.expireTime,
		}
	},
	methods: {
		deleteClient(id) {
			axios.delete(generateUrl('apps/oidc/clients/{id}', { id }))
				.then((response) => {
					// eslint-disable-next-line vue/no-mutating-props
					this.clients = this.clients.filter(client => client.id !== id)
				})
		},
		addClient() {
			this.newClient.error = false

			axios.post(
				generateUrl('apps/oidc/clients'),
				{
					name: this.newClient.name,
					redirectUri: this.newClient.redirectUri,
					signingAlg: this.newClient.signingAlg,
					type: this.newClient.type,
				}
			).then(response => {
				// eslint-disable-next-line vue/no-mutating-props
				this.clients.push(response.data)

				this.newClient.name = ''
				this.newClient.redirectUri = ''
				this.newClient.signingAlg = 'RS256'
				this.newClient.type = 'confidential'
			}).catch(reason => {
				this.newClient.error = true
				this.newClient.errorMsg = reason.response.data.message
			})
		},
		setTokenExpireTime() {
			axios.post(
				generateUrl('apps/oidc/expire'),
				{
					expireTime: this.expTime,
				}).then((response) => {
				// eslint-disable-next-line vue/no-mutating-props
				this.expTime = response.data.expire_time
				// eslint-disable-next-line vue/no-mutating-props
				this.expireTime = response.data.expire_time
			})
		},
		regenerateKeys() {
			axios.post(
				generateUrl('apps/oidc/genKeys'),
				{}).then((response) => {
				// eslint-disable-next-line vue/no-mutating-props
				this.publicKey = response.data.public_key
			})
		},
	},
}
</script>
<style scoped>
	table {
		max-width: 800px;
	}
</style>
