<!--
  - SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<NcSettingsSection :name="t('oidc', 'OpenID Connect Provider')"
		:description="t('oidc', 'OpenID Connect allows to authenticate at external services with {instanceName} user accounts.', { instanceName: oc.theme.name})">
		<span v-if="error" class="msg error">{{ errorMsg }}</span>

		<div style="display: flex; gap: 12px; border-bottom: 4px solid var(--color-primary-element-hover);">
			<NcButton aria-label="t('oidc', 'OpenID Connect clients')"
				style="border-bottom-left-radius: 0px; border-bottom-right-radius: 0px;"
				:text="t('oidc', 'OpenID Connect clients')"
				:variant="variantClients"
				@click="openOIDCTabClients" />
			<NcButton aria-label="t('oidc', 'Settings')"
				style="border-bottom-left-radius: 0px; border-bottom-right-radius: 0px;"
				:text="t('oidc', 'Settings')"
				:variant="variantSettings"
				@click="openOIDCTabSettings" />
			<NcButton aria-label="t('oidc', 'Public Key')"
				style="border-bottom-left-radius: 0px; border-bottom-right-radius: 0px;"
				:text="t('oidc', 'Public Key')"
				:variant="variantKeys"
				@click="openOIDCTabKeys" />
		</div>

		<div id="oidc_clients" style="display: block;">
			<h4>{{ t('oidc', 'Add client') }}</h4>
			<span v-if="newClient.error" class="msg error">{{ newClient.errorMsg }}</span>
			<form @submit.prevent="addClient">
				<div style="display: flex; gap: 4px;">
					<input id="name"
						v-model="newClient.name"
						type="text"
						name="name"
						style="width: 40%"
						:placeholder="t('oidc', 'Name')">
					<input id="redirectUri"
						v-model="newClient.redirectUri"
						type="url"
						name="redirectUri"
						style="width: 60%"
						:placeholder="t('oidc', 'Redirection URI')">
				</div>
				<div style="display: flex; gap: 4px;">
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
				</div>
				<input type="submit" class="button" :value="t('oidc', 'Add')">
			</form>
			<h4 v-if="localClients.length > 0">
				{{ t('oidc', 'List of clients') }}
			</h4>
			<div v-if="localClients"
				:key="version">
				<div v-if="localClients.length > 0" class="list">
					<OIDCItem v-for="client in localClients"
						:key="client.id"
						:client="client"
						:groups="groups"
						@addredirect="addRedirectUri"
						@deleteredirect="deleteRedirectUri"
						@delete="deleteClient"
						@updategroups="updateGroups"
						@updateflowtypes="updateFlowTypes"
						@updatetokentype="updateTokenType"
						@saveallowedscopes="setAllowedScopes"
						@saveemailregex="setEmailRegex" />
				</div>
			</div>
		</div>
		<div id="oidc_settings" style="display: none;">
			<h4>{{ t('oidc', 'Settings') }}</h4>
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
				{{ t('oidc', 'Default Access Token Type') }}
			</p>
			<select id="defaultTokenType"
				v-model="localDefaultTokenType"
				:placeholder="t('oidc', 'Default token type for new clients')"
				@change="setDefaultTokenType">
				<option disabled value="">
					{{ t('oidc', 'Select default token type for new clients') }}
				</option>
				<option value="opaque">
					{{ t('oidc', 'Opaque Access Token') }}
				</option>
				<option value="jwt">
					{{ t('oidc', 'JWT Access Token (RFC9068)') }}
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
				{{ t('oidc', 'Allow User Settings') }}
			</p>
			<select id="allowUserSettings"
				v-model="localAllowUserSettings"
				:placeholder="t('oidc', 'Define if user can make own user specific changes to settings')"
				@change="setAllowUserSettings">
				<option disabled value="">
					{{ t('oidc', 'Select if user is able to modify user specific settings') }}
				</option>
				<option value="no">
					{{ t('oidc', 'User cannot edit any settings') }}
				</option>
				<option value="enabled">
					{{ t('oidc', 'User can edit settings') }}
				</option>
			</select>

			<h4>
				{{ t('oidc', 'Restrict User Information') }}
			</h4>
			<NcSelect v-bind="userDataRestriction.props"
				v-model="userDataRestriction.props.value"
				:no-wrap="false"
				:input-label="t('oidc', 'Removed information from ID token and userinfo endpoint')"
				:placeholder="t('oidc', 'Select information to be omitted')"
				class="nc_select"
				@update:modelValue="updateRestrictUserInformation" />

			<h4>{{ t('oidc', 'Accepted Logout Redirect URIs') }}</h4>
			<div v-if="localLogoutRedirectUris.length > 0"
				:key="version"
				class="list">
				<RedirectItem v-for="redirectUri in localLogoutRedirectUris"
					:id="redirectUri.id"
					:key="redirectUri.id"
					:redirect-uri="redirectUri.redirectUri"
					@delete="deleteLogoutRedirectUri" />
			</div>
			<div class="grid-inner-2">
				<NcTextField v-model="newLogoutRedirectUri.redirectUri"
					style="width: 100%"
					type="url"
					name="redirectUri"
					:placeholder="t('oidc', 'Redirection URI')" />
				<NcButton :aria-label="t('oidc', 'Add Redirection URI')"
					:text="t('oidc', 'Add')"
					variant="secondary"
					@click="addLogoutRedirectUri" />
			</div>
		</div>
		<div id="oidc_keys" style="display: none;">
			<h4>{{ t('oidc', 'Public Key') }}</h4>
			<code>{{ localPublicKey }}</code>
			<br>
			<form @submit.prevent="regenerateKeys">
				<input type="submit" class="button" :value="t('oidc', 'Regenerate Keys')">
			</form>
		</div>
	</NcSettingsSection>
</template>

<script>
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import OIDCItem from './components/OIDCItem.vue'
import RedirectItem from './components/RedirectItem.vue'
import { generateUrl } from '@nextcloud/router'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default {
	name: 'App',
	components: {
		OIDCItem,
		RedirectItem,
		NcSettingsSection,
		NcTextField,
		NcButton,
		NcSelect,
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
		allowUserSettings: {
			type: String,
			required: true,
		},
		restrictUserInformation: {
			type: String,
			required: true,
		},
		defaultTokenType: {
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
				tokenType: '',
				allowedScopes: '',
				emailRegex: '',
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
			localAllowUserSettings: this.allowUserSettings,
			localDefaultTokenType: this.defaultTokenType,
			error: false,
			errorMsg: '',
			version: 0,
			variantClients: 'primary',
			variantSettings: 'secondary',
			variantKeys: 'secondary',
			userDataRestriction: {
				props: {
					multiple: true,
					keepOpen: true,
					options: [
						{
							label: t('oidc', 'Avatar'),
							value: 'avatar',
						},
						{
							label: t('oidc', 'Address'),
							value: 'address',
						},
						{
							label: t('oidc', 'Phone'),
							value: 'phone',
						},
						{
							label: t('oidc', 'Website'),
							value: 'website',
						},
					],
					value: this.generateRestrictUserInformationProperties(this.restrictUserInformation)
				},
			},
		}
	},
	computed: {
		oc() {
			return window.OC
		},
	},
	methods: {
		t,
		openOIDCTabClients() {
			document.getElementById('oidc_clients').style.display = 'block'
			document.getElementById('oidc_settings').style.display = 'none'
			document.getElementById('oidc_keys').style.display = 'none'
			this.variantClients = 'primary'
			this.variantSettings = 'secondary'
			this.variantKeys = 'secondary'
		},
		openOIDCTabSettings() {
			document.getElementById('oidc_clients').style.display = 'none'
			document.getElementById('oidc_settings').style.display = 'block'
			document.getElementById('oidc_keys').style.display = 'none'
			this.variantClients = 'secondary'
			this.variantSettings = 'primary'
			this.variantKeys = 'secondary'
		},
		openOIDCTabKeys() {
			document.getElementById('oidc_clients').style.display = 'none'
			document.getElementById('oidc_settings').style.display = 'none'
			document.getElementById('oidc_keys').style.display = 'block'
			this.variantClients = 'secondary'
			this.variantSettings = 'secondary'
			this.variantKeys = 'primary'
		},
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
				this.newClient.tokenType = ''
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
		setAllowUserSettings() {
			axios.post(
				generateUrl('apps/oidc/allowUserSettings'),
				{
					allowUserSettings: this.localAllowUserSettings,
				}).then((response) => {
				this.localAllowUserSettings = response.data.allow_user_settings
			})
		},
		setDefaultTokenType() {
			axios.post(
				generateUrl('apps/oidc/defaultTokenType'),
				{
					defaultTokenType: this.localDefaultTokenType,
				}).then((response) => {
				this.localDefaultTokenType = response.data.default_token_type
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
		setAllowedScopes(id, allowedScopes) {
			this.error = false

			axios.patch(
				generateUrl('apps/oidc/clients/allowed_scopes/{id}', { id }),
				{
					id,
					allowedScopes,
				},
			).then(response => {
				// Nothing to do
			}).catch(reason => {
				this.error = true
				this.errorMsg = reason
			})
		},
		setEmailRegex(id, emailRegex) {
			this.error = false

			axios.patch(
				generateUrl('apps/oidc/clients/email_regex/{id}', { id }),
				{
					id,
					emailRegex,
				},
			).then(response => {
				// Nothing to do
			}).catch(reason => {
				this.error = true
				this.errorMsg = reason
			})
		},
		updateRestrictUserInformation() {
			let tmpStr = ''
			if (this.userDataRestriction.props.value.length > 0) {
				for (const element of this.userDataRestriction.props.value) {
					tmpStr = tmpStr + element.value + ' '
				}
				tmpStr.trim()
			} else {
				tmpStr = 'no'
			}
			axios.post(
				generateUrl('apps/oidc/restrictUserInformation'),
				{
					restrictUserInformation: tmpStr,
				}).then((response) => {
				this.userDataRestriction.props.value = this.generateRestrictUserInformationProperties(response.data.restrict_user_information)
			})
		},
		generateRestrictUserInformationProperties(conf) {
			const tmpArr = conf.split(' ')
			const resultPropValue = []
			for (const element of tmpArr) {
				switch (element) {
				case 'avatar':
					resultPropValue.push({
						label: t('oidc', 'Avatar'),
						value: element,
					})
					break

				case 'address':
					resultPropValue.push({
						label: t('oidc', 'Address'),
						value: element,
					})
					break

				case 'phone':
					resultPropValue.push({
						label: t('oidc', 'Phone'),
						value: element,
					})
					break

				case 'website':
					resultPropValue.push({
						label: t('oidc', 'Website'),
						value: element,
					})
					break

				default:
					break
				}
			}
			return resultPropValue
		},
	},
}
</script>
<style>
	#oidc .settings-section {
		width: 95%;
	}

	#oidc .settings-section h2.settings-section__name {
		font-size: 20px !important;
		font-weight: bold !important;
	}
	#oidc .list {
		display: grid;
		grid-template-columns: 1fr;
	}
	#oidc .grid-inner-2 {
		display: grid;
		grid-template-columns: 7fr 1fr;
	}
</style>
