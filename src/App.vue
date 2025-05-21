<!--
  - SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<NcSettingsSection :name="t('oidc', 'OpenID Connect clients')"
		:description="t('oidc', 'OpenID Connect allows to authenticate at external services with {instanceName} user accounts.', { instanceName: oc.theme.name})">
		<span v-if="error" class="msg error">{{ errorMsg }}</span>
		<table v-if="localClients.length > 0" class="grid">
			<thead>
				<tr>
					<th id="headerContent" />
					<th id="headerRemove">
&nbsp;
					</th>
				</tr>
			</thead>
			<tbody v-if="localClients"
				:key="version">
				<OIDCItem v-for="client in localClients"
					:key="client.id"
					:client="client"
					:groups="groups"
					@addredirect="addRedirectUri"
					@deleteredirect="deleteRedirectUri"
					@delete="deleteClient"
					@updategroups="updateGroups"
					@updateflowtypes="updateFlowTypes"
					@updatetokentype="updateTokenType" />
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
			v-model="localExpireTime"
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

		<p>{{ t('oidc', 'Refresh Token Expire Time') }}</p>
		<select id="refreshExpireTime"
			v-model="localRefreshExpireTime"
			:placeholder="t('oidc', 'Refresh Token Expire Time')"
			@change="setRefreshExpireTime">
			<option disabled value="">
				{{ t('oidc', 'Select Refresh Token Expire Time') }}
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
			<option value="43200">
				{{ t('oidc', '12 hours') }}
			</option>
			<option value="86400">
				{{ t('oidc', '1 day') }}
			</option>
			<option value="604800">
				{{ t('oidc', '7 days') }}
			</option>
			<option value="never">
				{{ t('oidc', 'Never') }}
			</option>
		</select>

		<p style="margin-top: 1.5em;">
			{{ t('oidc', 'Dynamic Client Registration') }}
		</p>
		<select id="dynamicClientRegistration"
			v-model="localDynamicClientRegistration"
			:placeholder="t('oidc', 'Enable or disable Dynamic Client Registration')"
			@change="setDynamicClientRegistration">
			<option disabled value="">
				{{ t('oidc', 'Select to enable/disable the Dynamic Client Registration') }}
			</option>
			<option value="false">
				{{ t('oidc', 'Disable') }}
			</option>
			<option value="true">
				{{ t('oidc', 'Enable') }}
			</option>
		</select>

		<p style="margin-top: 1.5em;">
			{{ t('oidc', 'Email Verified Flag') }}
		</p>
		<select id="overwriteEmailVerified"
			v-model="localOverwriteEmailVerified"
			:placeholder="t('oidc', 'Source for email verified flag in token')"
			@change="setOverwriteEmailVerified">
			<option disabled value="">
				{{ t('oidc', 'Select behaviour for setting email verified flag') }}
			</option>
			<option value="false">
				{{ t('oidc', 'Use Nextcloud account information') }}
			</option>
			<option value="true">
				{{ t('oidc', 'Set to always verified') }}
			</option>
		</select>

		<p style="margin-top: 1.5em;">
			{{ t('oidc', 'Accepted Logout Redirect URIs') }}
		</p>
		<table v-if="localLogoutRedirectUris.length > 0" class="grid">
			<tbody v-if="localLogoutRedirectUris"
				:key="version">
				<RedirectItem v-for="redirectUri in localLogoutRedirectUris"
					:id="redirectUri.id"
					:key="redirectUri.id"
					:redirect-uri="redirectUri.redirectUri"
					@delete="deleteLogoutRedirectUri" />
			</tbody>
		</table>
		<form @submit.prevent="addLogoutRedirectUri">
			<input id="redirectUri"
				v-model="newLogoutRedirectUri.redirectUri"
				type="url"
				name="redirectUri"
				:placeholder="t('oidc', 'Redirection URI')">
			<input type="submit" class="button" :value="t('oidc', 'Add')">
		</form>
		<p style="margin-top: 1.5em;">
			{{ t('oidc', 'Public Key') }}
		</p>
		<code>{{ localPublicKey }}</code>
		<br>
		<form @submit.prevent="regenerateKeys">
			<input type="submit" class="button" :value="t('oidc', 'Regenerate Keys')">
		</form>
	</NcSettingsSection>
</template>

<script>
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import OIDCItem from './components/OIDCItem.vue'
import RedirectItem from './components/RedirectItem.vue'
import { generateUrl } from '@nextcloud/router'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

export default {
	name: 'App',
	components: {
		OIDCItem,
		RedirectItem,
		NcSettingsSection,
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
		refreshExpireTime: {
			type: String,
			required: true,
		},
		publicKey: {
			type: String,
			required: true,
		},
		groups: {
			type: Array,
			required: true,
		},
		logoutRedirectUris: {
			type: Array,
			required: true,
		},
		overwriteEmailVerified: {
			type: String,
			required: true,
		},
		dynamicClientRegistration: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			localClients: this.clients,
			newClient: {
				name: '',
				redirectUri: '',
				signingAlg: 'RS256',
				type: 'confidential',
				flowType: 'code',
				tokenType: 'opaque',
				errorMsg: '',
				error: false,
			},
			newLogoutRedirectUri: {
				redirectUri: '',
			},
			localLogoutRedirectUris: this.logoutRedirectUris,
			localPublicKey: this.publicKey,
			localExpireTime: this.expireTime,
			localRefreshExpireTime: this.refreshExpireTime,
			localOverwriteEmailVerified: this.overwriteEmailVerified,
			localDynamicClientRegistration: this.dynamicClientRegistration,
			error: false,
			errorMsg: '',
			version: 0,
		}
	},
	computed: {
		oc() {
			return window.OC
		},
	},
	methods: {
		t,
		deleteRedirectUri(id) {
			axios.delete(generateUrl('apps/oidc/clients/redirect/{id}', { id }))
				.then((response) => {
					this.localClients.splice(0, this.localClients.length)
					for (const entry of response.data) {
						this.localClients.push(entry)
					}
					this.version += 1
				}).catch(reason => {
					this.error = true
					this.errorMsg = reason
				})
		},
		addRedirectUri(id, uri) {
			this.error = false

			axios.post(
				generateUrl('apps/oidc/clients/redirect'),
				{
					id,
					redirectUri: uri,
				},
			).then(response => {
				this.localClients.splice(0, this.localClients.length)
				for (const entry of response.data) {
					this.localClients.push(entry)
				}
				this.version += 1
			}).catch(reason => {
				this.error = true
				this.errorMsg = reason
			})
		},
		deleteLogoutRedirectUri(id) {
			this.error = false

			axios.delete(generateUrl('apps/oidc/logoutRedirect/{id}', { id }))
				.then((response) => {
					this.localLogoutRedirectUris = response.data
					this.version += 1
				}).catch(reason => {
					this.error = true
					this.errorMsg = reason
				})
		},
		addLogoutRedirectUri() {
			this.error = false

			axios.post(
				generateUrl('apps/oidc/logoutRedirect'),
				{
					redirectUri: this.newLogoutRedirectUri.redirectUri,
				},
			).then(response => {
				this.localLogoutRedirectUris = response.data
				this.newLogoutRedirectUri.redirectUri = ''
				this.version += 1
			}).catch(reason => {
				this.error = true
				this.errorMsg = reason
			})
		},
		deleteClient(id) {
			axios.delete(generateUrl('apps/oidc/clients/{id}', { id }))
				.then((response) => {
					this.localClients = this.localClients.filter(client => client.id !== id)
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
					flowType: this.newClient.flowType,
					tokenType: this.newClient.tokenType,
				},
			).then(response => {
				this.localClients.push(response.data)

				this.newClient.name = ''
				this.newClient.redirectUri = ''
				this.newClient.signingAlg = 'RS256'
				this.newClient.type = 'confidential'
				this.newClient.flowType = 'code'
				this.newClient.tokenType = 'opaque'
			}).catch(reason => {
				this.newClient.error = true
				this.newClient.errorMsg = reason.response.data.message
			})
		},
		setTokenExpireTime() {
			axios.post(
				generateUrl('apps/oidc/expire'),
				{
					expireTime: this.localExpireTime,
				}).then((response) => {
				this.localExpireTime = response.data.expire_time
			})
		},
		setRefreshExpireTime() {
			axios.post(
				generateUrl('apps/oidc/refreshExpire'),
				{
					refreshExpireTime: this.localRefreshExpireTime,
				}).then((response) => {
				this.localRefreshExpireTime = response.data.refresh_expire_time
			})
		},
		setOverwriteEmailVerified() {
			axios.post(
				generateUrl('apps/oidc/overwriteEmailVerified'),
				{
					overwriteEmailVerified: this.localOverwriteEmailVerified,
				}).then((response) => {
				this.localOverwriteEmailVerified = response.data.overwrite_email_verified
			})
		},
		setDynamicClientRegistration() {
			axios.post(
				generateUrl('apps/oidc/dynamicClientRegistration'),
				{
					dynamicClientRegistration: this.localDynamicClientRegistration,
				}).then((response) => {
				this.localDynamicClientRegistration = response.data.dynamic_client_registration
			})
		},
		regenerateKeys() {
			axios.post(
				generateUrl('apps/oidc/genKeys'),
				{}).then((response) => {
				this.localPublicKey = response.data.public_key
			})
		},
		updateGroups(id, groups) {
			this.error = false

			axios.patch(
				generateUrl('apps/oidc/clients/groups/{id}', { id }),
				{
					id,
					groups,
				},
			).then(response => {
				// Nothing to do
			}).catch(reason => {
				this.error = true
				this.errorMsg = reason
			})
		},
		updateFlowTypes(id, flowTypes) {
			this.error = false
			let resultingFlowTypes = 'code'
			if (flowTypes && flowTypes.value === 'code id_token') {
				resultingFlowTypes = 'code id_token'
			}

			axios.patch(
				generateUrl('apps/oidc/clients/flows/{id}', { id }),
				{
					id,
					flowType: resultingFlowTypes,
				},
			).then(response => {
				// Nothing to do
			}).catch(reason => {
				this.error = true
				this.errorMsg = reason
			})
		},
		updateTokenType(id, tokenType) {
			this.error = false

			axios.patch(
				generateUrl('apps/oidc/clients/token_type/{id}', { id }),
				{
					id,
					tokenType,
				},
			).then(response => {
				// Nothing to do
			}).catch(reason => {
				this.error = true
				this.errorMsg = reason
			})
		},
	},
}
</script>
<style>
	table {
		max-width: 800px;
	}
	#oidc .settings-section h2.settings-section__name {
		font-size: 20px !important;
		font-weight: bold !important;
	}
</style>
