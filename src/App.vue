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
			<input type="submit" class="button" :value="t('oidc', 'Add')">
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
	},
	data() {
		return {
			newClient: {
				name: '',
				redirectUri: '',
				signingAlg: 'RS256',
				errorMsg: '',
				error: false,
			},
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
				}
			).then(response => {
				// eslint-disable-next-line vue/no-mutating-props
				this.clients.push(response.data)

				this.newClient.name = ''
				this.newClient.redirectUri = ''
				this.newClient.signingAlg = ''
			}).catch(reason => {
				this.newClient.error = true
				this.newClient.errorMsg = reason.response.data.message
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
