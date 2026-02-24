<!--
  - SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div>
		<NcSettingsSection :name="t('oidc', 'OpenID Connect Provider')"
			:description="t('oidc', 'OpenID Connect allows to authenticate at external services with {instanceName} user accounts.', { instanceName: oc.theme.name})">
			<NcNoteCard v-if="error"
				type="error"
				:text="errorMsg" />

			<div style="display: flex; gap: 12px; border-bottom: 4px solid var(--color-primary-element-hover); width: calc(100% - 24px);">
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

			<div id="oidc_new_client" style="display: none;">
				<NcButton alignment="start"
					:text="t('oidc', 'Back')"
					variant="tertiary-no-background"
					wide
					@click="openOIDCTabClients">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowLeft" />
					</template>
				</NcButton>
				<h4>{{ t('oidc', 'Add client') }}</h4>
				<form @submit.prevent="addClient">
					<div class="container">
						<NcTextField v-model="newClient.name"
							type="text"
							name="name"
							style="max-width: 100%; width: 740px;"
							:label="t('oidc', 'Name')"
							:placeholder="t('oidc', 'OIDC Client Name e.g Client 1')" />
						<NcTextField v-model="newClient.redirectUri"
							type="url"
							name="redirectUri"
							style="max-width: 100%; width: 740px;"
							:label="t('oidc', 'Redirection URI')"
							:placeholder="t('oidc', 'https://example.com/redirect')" />
						<NcSelect v-bind="signingAlgs.props"
							v-model="signingAlgs.props.value"
							style="max-width: 100%; width: 740px;"
							:no-wrap="false"
							:input-label="t('oidc', 'Signing Algorithm')"
							:placeholder="t('oidc', 'Select Signing Algorithm')"
							@update:modelValue="newClientUpdateSigningAlg" />
						<NcSelect v-bind="clientTypes.props"
							v-model="clientTypes.props.value"
							style="max-width: 100%; width: 740px;"
							:no-wrap="false"
							:input-label="t('oidc', 'Client Type')"
							:placeholder="t('oidc', 'Select Type')"
							@update:modelValue="newClientUpdateType" />
						<div style="display: flex; gap: 12px; margin-top: 20px;">
							<NcButton :text="t('oidc', 'Cancel')"
								:aria-label="t('oidc', 'Cancel')"
								variant="secondary"
								@click="openOIDCTabClients" />
							<NcButton type="submit"
								:aria-label="t('oidc', 'Add')"
								variant="primary">
								{{ t('oidc', 'Add') }}
							</NcButton>
						</div>
					</div>
				</form>
			</div>
			<div id="oidc_edit_client" style="display: none;">
				<NcButton alignment="start"
					:text="t('oidc', 'Back')"
					variant="tertiary-no-background"
					wide
					@click="openOIDCTabClients">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowLeft" />
					</template>
				</NcButton>
				<h4>{{ t('oidc', 'Edit client') }}</h4>
				<div class="container">
					<NcFormBox>
						<NcFormBoxCopyButton :label="t('oidc', 'Name')"
							:value="editClient.name" />
						<div style="display: flex; gap: 8px; flex-wrap: wrap;">
							<NcChip :text="t('oidc', 'Signing Algorithm').concat(' - ', editClient.signingAlg)"
								:icon-path="mdiCertificate"
								no-close
								variant="secondary" />
							<NcChip :text="t('oidc', 'Type').concat(' - ', editClient.type)"
								:icon-path="mdiWeb"
								no-close
								variant="secondary" />
						</div>
						<NcFormBoxCopyButton :label="t('oidc', 'Client Identifier')" :value="editClient.clientId" />
						<NcFormBoxCopyButton v-if="!isPublic" :label="t('oidc', 'Secret')" :value="editClient.clientSecret" />
					</NcFormBox>
					<NcFormGroup :label="t('oidc', 'Redirection URIs')"
						style="max-width: 100%; width: 740px;">
						<div v-if="editClient.redirectUris.length > 0"
							:key="version">
							<RedirectItem v-for="redirectUri in editClient.redirectUris"
								:id="redirectUri.id"
								:key="redirectUri.id"
								:redirect-uri="redirectUri.redirect_uri"
								style="width: 100%;"
								@delete="deleteRedirectUri" />
						</div>
						<div class="grid-inner-2">
							<NcTextField v-model="editClient.addRedirectUri"
								type="url"
								name="redirectUri"
								:label="t('oidc', 'Redirection URI')"
								:placeholder="t('oidc', 'https://example.com/redirect')" />
							<NcButton :aria-label="t('oidc', 'Add Redirection URI')"
								:text="t('oidc', 'Add')"
								variant="secondary"
								@click="addRedirectUri(editClient.id, editClient.addRedirectUri)" />
						</div>
					</NcFormGroup>
					<NcFormGroup :label="t('oidc', 'Flows')"
						style="max-width: 100%; width: 740px;">
						<NcSelect v-bind="editClient.flowData.props"
							v-model="editClient.flowData.props.value"
							:input-label="t('oidc', 'Flows allowed to be used with the client.')"
							:placeholder="t('oidc', 'Flows allowed to be used with the client.')"
							:no-wrap="true"
							@update:model-value="editClientUpdateFlowType" />
					</NcFormGroup>
					<NcFormGroup :label="t('oidc', 'Access Token Type')"
						style="max-width: 100%; width: 740px;">
						<NcCheckboxRadioSwitch v-model="editClient.tokenType"
							value="opaque"
							name="token_type"
							type="radio">
							{{ t('oidc', 'Opaque Access Token') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="editClient.tokenType"
							value="jwt"
							name="token_type"
							type="radio">
							{{ t('oidc', 'JWT Access Token (RFC9068)') }}
						</NcCheckboxRadioSwitch>
					</NcFormGroup>
					<NcFormGroup :label="t('oidc', 'Group Limitations')"
						style="max-width: 100%; width: 740px;">
						<NcSelect v-bind="editClient.groupData.props"
							v-model="editClient.groupData.props.value"
							:input-label="t('oidc', 'Only users in one of the following groups are allowed to use the client.')"
							:placeholder="t('oidc', 'Groups allowed to use the client.')"
							:no-wrap="false" />
					</NcFormGroup>
					<NcFormGroup :label="t('oidc', 'Further Settings')"
						style="max-width: 100%; width: 740px;">
						<NcTextField v-model="editClient.allowedScopes"
							:label="t('oidc', 'Allowed Scopes')"
							:placeholder="t('oidc', 'openid profile roles')"
							:helper-text="t('oidc', 'Define the allowed scopes for the client separated by a whitespace, e.g. openid profile roles. Do not enter any value to allow all scopes.')" />
						<NcTextField id="emailRegex"
							v-model="editClient.emailRegex"
							:label="t('oidc', 'Email Selection')"
							:placeholder="t('oidc', '.*@domain.tld')"
							:helper-text="t('oidc', 'Usually the primary email address is used during OpenID control flows. If you wish to use other email adresses (defined as secondary email address in personal settings) you could define a regular expression for selecting the used email address. E.g. .*@domain.tld')"
							type="text" />
						<NcTextField id="resourceUrl"
							v-model="editClient.resourceUrl"
							:label="t('oidc', 'Resource URL (RFC 9728)')"
							:placeholder="t('oidc', 'https://resource-server.com/')"
							:helper-text="t('oidc', 'Resource URL (RFC 9728) for token introspection authorization. Clients with this URL can introspect tokens issued to this resource.')"
							type="url" />
					</NcFormGroup>
					<NcFormGroup :label="t('oidc', 'Custom Claims')"
						style="max-width: 100%; width: 740px;">
						<NcFormBox>
							<NcFormBoxButton v-for="cclaim in editClient.customClaims"
								:key="cclaim.id"
								:label="cclaim.name.concat('@', cclaim.scope).concat(' - ', cclaim.function)"
								@click="detailsCustomClaim(cclaim.id)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiArrowRight" inline />
								</template>
							</NcFormBoxButton>
							<NcButton wide
								@click="newCustomClaim">
								<template #icon>
									<NcIconSvgWrapper :path="mdiPlus" />
								</template>
								{{ t('oidc', 'Add custom claim') }}
							</NcButton>
						</NcFormBox>
					</NcFormGroup>
					<div style="display: flex; gap: 12px; margin-top: 20px;">
						<NcButton :text="t('oidc', 'Cancel')"
							:aria-label="t('oidc', 'Cancel')"
							variant="secondary"
							@click="openOIDCTabClients" />
						<NcButton :text="t('oidc', 'Save')"
							:aria-label="t('oidc', 'Save')"
							variant="primary"
							@click="saveEditClient">
							{{ t('oidc', 'Save') }}
						</NcButton>
						<NcButton :text="t('oidc', 'Delete')"
							:aria-label="t('oidc', 'Delete')"
							variant="error"
							@click="deleteClient">
							{{ t('oidc', 'Delete') }}
						</NcButton>
					</div>
				</div>
			</div>
			<div id="oidc_clients" style="display: block;">
				<h4 v-if="localClients.length > 0">
					{{ t('oidc', 'List of clients') }}
				</h4>
				<div :key="version"
					class="container">
					<NcFormBox>
						<OIDCItem v-for="client in localClients"
							:key="client.id"
							:client="client"
							@editclient="openOIDCTabEditClient" />
						<NcButton wide
							@click="openOIDCTabNewClient">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPlus" />
							</template>
							{{ t('oidc', 'Add client') }}
						</NcButton>
					</NcFormBox>
				</div>
			</div>
			<div id="oidc_settings" style="display: none;">
				<h4>{{ t('oidc', 'Settings') }}</h4>
				<div class="container">
					<div class="container-inner">
						<p>{{ t('oidc', 'Token Expire Time') }}</p>
						<select id="expireTime"
							v-model="localExpireTime"
							:placeholder="t('oidc', 'Token Expire Time')"
							style="max-width: 100%; width: 740px;"
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
					</div>
					<div class="container-inner">
						<p>{{ t('oidc', 'Refresh Token Expire Time') }}</p>
						<select id="refreshExpireTime"
							v-model="localRefreshExpireTime"
							:placeholder="t('oidc', 'Refresh Token Expire Time')"
							style="max-width: 100%; width: 740px;"
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
					</div>
					<div class="container-inner">
						<p style="margin-top: 1em;">
							{{ t('oidc', 'Dynamic Client Registration') }}
						</p>
						<select id="dynamicClientRegistration"
							v-model="localDynamicClientRegistration"
							:placeholder="t('oidc', 'Enable or disable Dynamic Client Registration')"
							style="max-width: 100%; width: 740px;"
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
					</div>
					<div class="container-inner">
						<p style="margin-top: 1em;">
							{{ t('oidc', 'Default Access Token Type') }}
						</p>
						<select id="defaultTokenType"
							v-model="localDefaultTokenType"
							:placeholder="t('oidc', 'Default token type for new clients')"
							style="max-width: 100%; width: 740px;"
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
					</div>
					<div class="container-inner">
						<p style="margin-top: 1em;">
							{{ t('oidc', 'Email Verified Flag') }}
						</p>
						<select id="overwriteEmailVerified"
							v-model="localOverwriteEmailVerified"
							:placeholder="t('oidc', 'Source for email verified flag in token')"
							style="max-width: 100%; width: 740px;"
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
					</div>
					<div class="container-inner">
						<p style="margin-top: 1em;">
							{{ t('oidc', 'Allow User Settings') }}
						</p>
						<select id="allowUserSettings"
							v-model="localAllowUserSettings"
							:placeholder="t('oidc', 'Define if user can make own user specific changes to settings')"
							style="max-width: 100%; width: 740px;"
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
					</div>
					<div class="container-inner">
						<p style="margin-top: 1em;">
							{{ t('oidc', 'Refresh Token Behavior') }}
						</p>
						<select id="provideRefreshTokenAlways"
							v-model="localProvideRefreshTokenAlways"
							:placeholder="t('oidc', 'Define refresh token issuance behavior')"
							style="max-width: 100%; width: 740px;"
							@change="setProvideRefreshTokenAlways">
							<option disabled value="">
								{{ t('oidc', 'Select refresh token behavior') }}
							</option>
							<option value="false">
								{{ t('oidc', 'OIDC Compliant (require offline_access scope)') }}
							</option>
							<option value="true">
								{{ t('oidc', 'Always provide refresh tokens (legacy mode)') }}
							</option>
						</select>
						<p class="hint" style="margin-top: 0.5em; font-size: 0.9em; color: var(--color-text-maxcontrast);">
							{{ t('oidc', 'OIDC-compliant clients must request the offline_access scope to receive refresh tokens. Enable legacy mode only if you have non-compliant clients that cannot be updated.') }}
						</p>
					</div>
					<div class="container-inner">
						<p style="margin-top: 1em;">
							{{ t('oidc', 'Restrict User Information') }}
						</p>
						<NcSelect v-bind="userDataRestriction.props"
							v-model="userDataRestriction.props.value"
							style="max-width: 100%; width: 740px;"
							:no-wrap="false"
							:input-label="t('oidc', 'Removed information from ID token and userinfo endpoint')"
							:placeholder="t('oidc', 'Select information to be omitted')"
							@update:modelValue="updateRestrictUserInformation" />
					</div>
					<div class="container-inner">
						<p style="margin-top: 1em;">
							{{ t('oidc', 'Accepted Logout Redirect URIs') }}
						</p>
						<div v-if="localLogoutRedirectUris.length > 0"
							:key="version"
							style="max-width: 100%; width: 740px;">
							<RedirectItem v-for="redirectUri in localLogoutRedirectUris"
								:id="redirectUri.id"
								:key="redirectUri.id"
								:redirect-uri="redirectUri.redirectUri"
								style="max-width: calc(100% - 10px); width: 740px;"
								@delete="deleteLogoutRedirectUri" />
						</div>
						<div class="grid-inner-2">
							<NcTextField v-model="newLogoutRedirectUri.redirectUri"
								type="url"
								name="redirectUri"
								:label="t('oidc', 'Redirection URI')"
								:placeholder="t('oidc', 'Redirection URI')" />
							<NcButton :aria-label="t('oidc', 'Add Redirection URI')"
								:text="t('oidc', 'Add')"
								variant="secondary"
								@click="addLogoutRedirectUri" />
						</div>
					</div>
				</div>
			</div>
			<div id="oidc_keys" style="display: none;">
				<h4>{{ t('oidc', 'Public Key') }}</h4>
				<div class="container">
					<pre><code>{{ localPublicKey }}</code></pre>
					<br>
					<form @submit.prevent="regenerateKeys">
						<input type="submit" class="button" :value="t('oidc', 'Regenerate Keys')">
					</form>
				</div>
			</div>
		</NcSettingsSection>
		<NcModal v-if="customClaimModal.show"
			size="normal"
			:name="t('oidc', 'Custom Claim')"
			out-transition
			@close="closeCustomClaimModal">
			<h4>{{ customClaimModal.isEdit ? t('oidc', 'Edit Custom Claim') : t('oidc', 'Add Custom Claim') }}</h4>
			<NcTextField v-model="customClaimModal.name"
				:label="t('oidc', 'Name')"
				:disabled="customClaimModal.isEdit"
				:placeholder="t('oidc', 'is_admin')"
				:helper-text="t('oidc', 'Define the name of the custom claim.')" />
			<NcTextField v-model="customClaimModal.scope"
				:label="t('oidc', 'Scope')"
				:placeholder="t('oidc', 'profile')"
				:helper-text="t('oidc', 'The custom claim will be provided when the defined scope is requested.')" />
			<NcSelect v-bind="customClaimModal.function.props"
				v-model="customClaimModal.function.props.value"
				style="width: 100%;"
				:no-wrap="false"
				:input-label="t('oidc', 'Function')"
				:placeholder="t('oidc', 'Select function to generate the custom claim value')"
				@update:modelValue="updateCustomClaimFunction" />
			<NcNoteCard v-if="customClaimModal.functionInfo" type="info" :text="customClaimModal.functionInfo" />
			<NcTextField v-if="customClaimModal.parametersRequired"
				v-model="customClaimModal.parameters"
				:label="t('oidc', 'Parameters')"
				:placeholder="t('oidc', 'param1, param2, ...')"
				:helper-text="t('oidc', 'Comma separated list of parameters to be passed to the selected function. Possible parameters depend on the selected function.')" />
			<NcNoteCard v-if="customClaimModal.error"
				type="error"
				:text="customClaimModal.errorMsg" />
			<div style="display: flex; gap: 12px; margin-top: 20px;">
				<NcButton :text="t('oidc', 'Cancel')"
					:aria-label="t('oidc', 'Cancel')"
					variant="secondary"
					@click="closeCustomClaimModal" />
				<NcButton :text="t('oidc', 'Save')"
					:aria-label="t('oidc', 'Save')"
					variant="primary"
					@click="saveCustomClaim">
					{{ t('oidc', 'Save') }}
				</NcButton>
				<NcButton v-if="customClaimModal.isEdit"
					:text="t('oidc', 'Delete')"
					:aria-label="t('oidc', 'Delete')"
					variant="error"
					@click="deleteCustomClaim">
					{{ t('oidc', 'Delete') }}
				</NcButton>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import OIDCItem from './components/OIDCItem.vue'
import RedirectItem from './components/RedirectItem.vue'
import { generateUrl } from '@nextcloud/router'
import { mdiArrowRight, mdiArrowLeft, mdiPlus, mdiCertificate, mdiWeb } from '@mdi/js'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcFormGroup from '@nextcloud/vue/components/NcFormGroup'
import NcFormBox from '@nextcloud/vue/components/NcFormBox'
import NcFormBoxButton from '@nextcloud/vue/components/NcFormBoxButton'
import NcFormBoxCopyButton from '@nextcloud/vue/components/NcFormBoxCopyButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcModal from '@nextcloud/vue/components/NcModal'

export default {
	name: 'App',
	components: {
		OIDCItem,
		RedirectItem,
		NcSettingsSection,
		NcTextField,
		NcButton,
		NcSelect,
		NcFormGroup,
		NcFormBox,
		NcFormBoxButton,
		NcFormBoxCopyButton,
		NcIconSvgWrapper,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcChip,
		NcModal,
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
		provideRefreshTokenAlways: {
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
			},
			editClient: {
				id: '',
				name: '',
				redirectUris: [],
				clientId: '',
				clientSecret: '',
				signingAlg: '',
				type: '',
				renderSecret: false,
				addRedirectUri: '',
				tokenType: '',
				allowedScopes: '',
				emailRegex: '',
				resourceUrl: '',
				customClaims: [],
				flowData: {
					props: {
						inputId: '',
						multiple: false,
						closeOnSelect: true,
						options: [
							{
								label: t('oidc', 'Code Authorization Flow'),
								value: 'code',
							},
							{
								label: t('oidc', 'Code & Implicit Authorization Flow'),
								value: 'code id_token',
							},
						],
						value: [
							{
								label: '',
								value: '',
							},
						],
					},
				},
				groupData: {
					props: {
						inputId: '',
						multiple: true,
						closeOnSelect: true,
						options: this.groups,
						value: [],
					},
				},
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
			localProvideRefreshTokenAlways: this.provideRefreshTokenAlways,
			error: false,
			errorMsg: '',
			customClaimModal: {
				show: false,
				isEdit: false,
				id: null,
				name: '',
				scope: '',
				parameters: '',
				functionInfo: false,
				parametersRequired: false,
				error: false,
				errorMsg: '',
				function: {
					props: {
						multiple: false,
						required: true,
						keepOpen: false,
						options: [
							{
								label: t('oidc', 'User is administrator'),
								value: 'isAdmin',
							},
							{
								label: t('oidc', 'User is group administrator'),
								value: 'isGroupAdmin',
							},
							{
								label: t('oidc', 'User has a specific role'),
								value: 'hasRole',
							},
							{
								label: t('oidc', 'User is in a specific group'),
								value: 'isInGroup',
							},
							{
								label: t('oidc', 'Return users primary email address'),
								value: 'getUserEmail',
							},
							{
								label: t('oidc', 'Return users groups as array of group IDs'),
								value: 'getUserGroups',
							},
							{
								label: t('oidc', 'Return users groups as array of display names'),
								value: 'getUserGroupsDisplayName',
							},
						],
						value: [
							{
								label: t('oidc', 'User is administrator'),
								value: 'isAdmin',
							},
						],
					},
				},
			},
			version: 0,
			variantClients: 'primary',
			variantSettings: 'secondary',
			variantKeys: 'secondary',
			clientTypes: {
				props: {
					multiple: false,
					keepOpen: false,
					options: [
						{
							label: t('oidc', 'Confidential'),
							value: 'confidential',
						},
						{
							label: t('oidc', 'Public'),
							value: 'public',
						},
					],
					value: [
						{
							label: t('oidc', 'Confidential'),
							value: 'confidential',
						},
					],
				},
			},
			signingAlgs: {
				props: {
					multipe: false,
					keepOpen: false,
					options: [
						{
							label: 'RS256',
							value: 'RS256',
						},
						{
							label: 'HS256',
							value: 'HS256',
						},
					],
					value: [
						{
							label: 'RS256',
							value: 'RS256',
						},
					],
				},
			},
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
					value: this.generateRestrictUserInformationProperties(this.restrictUserInformation),
				},
			},
			// expose mdi icons to the template
			mdiArrowRight,
			mdiArrowLeft,
			mdiPlus,
			mdiCertificate,
			mdiWeb,
		}
	},
	computed: {
		oc() {
			return window.OC
		},
		isPublic() {
			return this.editClient.type === 'public'
		},
	},
	methods: {
		t,
		clearError() {
			this.error = false
			this.errorMsg = ''
		},
		// helper to extract a human readable message from axios errors
		extractErrorMessage(error) {
			// axios typical response: error.response.data.message or error.response.data
			if (error && error.response) {
				const data = error.response.data
				// common patterns:
				if (data && typeof data === 'object' && data.message) {
					return data.message
				}
				if (data && typeof data === 'string') {
					return data
				}
				// sometimes Nextcloud returns an object with 'error' or other fields
				if (data && typeof data === 'object') {
					// try common keys
					return data.error || data.message || JSON.stringify(data)
				}
			}
			// fallback to axios error message or stringified object
			return error && error.message ? error.message : String(error)
		},
		scrollTop() {
			const content = document.querySelector('#app-content')
			if (content) {
				content.scrollTo({
					top: 0,
					left: 0,
					behavior: 'smooth',
				})
			}
		},
		updateCustomClaimFunction() {
			if (this.customClaimModal.function.props.value !== null && !Array.isArray(this.customClaimModal.function.props.value)) {
				const tmpLabel = this.customClaimModal.function.props.value.label
				const tmpValue = this.customClaimModal.function.props.value.value
				this.customClaimModal.function.props.value = [
					{
						label: tmpLabel,
						value: tmpValue,
					},
				]
			}
			let selectedFunction = false
			if (this.customClaimModal.function.props.value !== null) {
				selectedFunction = this.customClaimModal.function.props.options.find(option => option.value === this.customClaimModal.function.props.value[0].value)
			}
			if (selectedFunction) {
				switch (selectedFunction.value) {
				case 'isAdmin':
					this.customClaimModal.functionInfo = t('oidc', 'The claim value will be true if the user is administrator, otherwise false.')
					this.customClaimModal.parametersRequired = false
					break
				case 'isGroupAdmin':
					this.customClaimModal.functionInfo = t('oidc', 'The claim value will be true if the user is group administrator, otherwise false. Required parameter: group ID of the group for which to check the group administrator status.')
					this.customClaimModal.parametersRequired = true
					break
				case 'hasRole':
					this.customClaimModal.functionInfo = t('oidc', 'The claim value will be true if the user has the specified role, otherwise false. Required parameter: role name')
					this.customClaimModal.parametersRequired = true
					break
				case 'isInGroup':
					this.customClaimModal.functionInfo = t('oidc', 'The claim value will be true if the user is in the specified group, otherwise false. Required parameter: group ID of the group for which to check the membership.')
					this.customClaimModal.parametersRequired = true
					break
				case 'getUserEmail':
					this.customClaimModal.functionInfo = t('oidc', 'The claim value will be the users primary email address.')
					this.customClaimModal.parametersRequired = false
					break
				case 'getUserGroups':
					this.customClaimModal.functionInfo = t('oidc', 'The claim value will be an array of group IDs of the groups the user is in.')
					this.customClaimModal.parametersRequired = false
					break
				case 'getUserGroupsDisplayName':
					this.customClaimModal.functionInfo = t('oidc', 'The claim value will be an array of display names of the groups the user is in.')
					this.customClaimModal.parametersRequired = false
					break
				default:
					this.customClaimModal.functionInfo = false
					this.customClaimModal.parametersRequired = false
				}
			} else {
				this.customClaimModal.functionInfo = false
				this.customClaimModal.parametersRequired = false
			}
		},
		closeCustomClaimModal() {
			this.customClaimModal.show = false
			this.isEdit = false
			this.customClaimModal.id = null
			this.customClaimModal.name = ''
			this.customClaimModal.scope = ''
			this.customClaimModal.function.props.value = [{
				label: t('oidc', 'User is administrator'),
				value: 'isAdmin',
			}]
			this.customClaimModal.parameters = ''
			this.customClaimModal.functionInfo = false
			this.customClaimModal.parametersRequired = false
			this.customClaimModal.error = false
			this.customClaimModal.errorMsg = ''
		},
		openOIDCTabClients() {
			document.getElementById('oidc_clients').style.display = 'block'
			document.getElementById('oidc_settings').style.display = 'none'
			document.getElementById('oidc_keys').style.display = 'none'
			document.getElementById('oidc_new_client').style.display = 'none'
			document.getElementById('oidc_edit_client').style.display = 'none'
			this.variantClients = 'primary'
			this.variantSettings = 'secondary'
			this.variantKeys = 'secondary'
			this.clearError()
			this.scrollTop()
		},
		openOIDCTabSettings() {
			document.getElementById('oidc_clients').style.display = 'none'
			document.getElementById('oidc_settings').style.display = 'block'
			document.getElementById('oidc_keys').style.display = 'none'
			document.getElementById('oidc_new_client').style.display = 'none'
			document.getElementById('oidc_edit_client').style.display = 'none'
			this.variantClients = 'secondary'
			this.variantSettings = 'primary'
			this.variantKeys = 'secondary'
			this.clearError()
			this.scrollTop()
		},
		openOIDCTabKeys() {
			document.getElementById('oidc_clients').style.display = 'none'
			document.getElementById('oidc_settings').style.display = 'none'
			document.getElementById('oidc_keys').style.display = 'block'
			document.getElementById('oidc_new_client').style.display = 'none'
			document.getElementById('oidc_edit_client').style.display = 'none'
			this.variantClients = 'secondary'
			this.variantSettings = 'secondary'
			this.variantKeys = 'primary'
			this.clearError()
			this.scrollTop()
		},
		openOIDCTabNewClient() {
			document.getElementById('oidc_clients').style.display = 'none'
			document.getElementById('oidc_settings').style.display = 'none'
			document.getElementById('oidc_keys').style.display = 'none'
			document.getElementById('oidc_new_client').style.display = 'block'
			document.getElementById('oidc_edit_client').style.display = 'none'
			this.variantClients = 'primary'
			this.variantSettings = 'secondary'
			this.variantKeys = 'secondary'
			this.clearError()
			this.scrollTop()
		},
		openOIDCTabEditClient(id, clearError = true) {
			document.getElementById('oidc_clients').style.display = 'none'
			document.getElementById('oidc_settings').style.display = 'none'
			document.getElementById('oidc_keys').style.display = 'none'
			document.getElementById('oidc_new_client').style.display = 'none'
			document.getElementById('oidc_edit_client').style.display = 'block'
			this.variantClients = 'primary'
			this.variantSettings = 'secondary'
			this.variantKeys = 'secondary'
			this.scrollTop()
			if (clearError) this.clearError()

			const tmpClients = this.localClients.filter(client => client.id === id)
			const tmpClient = tmpClients.length > 0 ? tmpClients[0] : null

			if (tmpClient != null) {
				this.editClient.id = tmpClient.id
				this.editClient.name = tmpClient.name
				this.editClient.redirectUris = tmpClient.redirectUris
				this.editClient.clientId = tmpClient.clientId
				this.editClient.clientSecret = tmpClient.clientSecret
				this.editClient.signingAlg = tmpClient.signingAlg
				this.editClient.type = tmpClient.type
				this.editClient.renderSecret = false
				this.editClient.addRedirectUri = ''
				this.editClient.tokenType = tmpClient.tokenType
				this.editClient.allowedScopes = tmpClient.allowedScopes
				this.editClient.emailRegex = tmpClient.emailRegex
				this.editClient.resourceUrl = tmpClient.resourceUrl || ''
				this.editClient.customClaims = []
				this.editClient.flowData = {
					props: {
						inputId: tmpClient.id + '-flow-select',
						multiple: false,
						closeOnSelect: true,
						options: [
							{
								label: t('oidc', 'Code Authorization Flow'),
								value: 'code',
							},
							{
								label: t('oidc', 'Code & Implicit Authorization Flow'),
								value: 'code id_token',
							},
						],
						value: [
							{
								label: tmpClient.flowTypeLabel,
								value: tmpClient.flowType,
							},
						],
					},
				}
				this.editClient.groupData = {
					props: {
						inputId: tmpClient.id + '-group-select',
						multiple: true,
						closeOnSelect: true,
						options: this.groups,
						value: tmpClient.groups,
					},
				}
				this.getCustomClaims()
			}
		},
		detailsCustomClaim(id) {
			this.customClaimModal.show = true
			this.customClaimModal.isEdit = true
			const claim = this.editClient.customClaims.filter(cclaim => cclaim.id === id)[0]
			this.customClaimModal.id = claim.id
			this.customClaimModal.name = claim.name
			this.customClaimModal.scope = claim.scope
			const functionOption = this.customClaimModal.function.props.options.filter(option => option.value === claim.function)[0]
				|| {
					label: t('oidc', 'User is administrator'),
					value: 'isAdmin',
				}
			this.customClaimModal.function.props.value = [functionOption]
			this.customClaimModal.parameters = claim.parameter
				? claim.parameter
				: ''
			this.updateCustomClaimFunction()
			this.customClaimModal.error = false
			this.customClaimModal.errorMsg = ''
		},
		newCustomClaim() {
			this.customClaimModal.show = true
			this.customClaimModal.isEdit = false
			this.customClaimModal.id = null
			this.customClaimModal.name = ''
			this.customClaimModal.scope = ''
			this.customClaimModal.function.props.value = null
			this.customClaimModal.parameters = ''
			this.updateCustomClaimFunction()
			this.customClaimModal.error = false
			this.customClaimModal.errorMsg = ''
		},
		saveCustomClaim() {
			this.customClaimModal.error = false

			if (this.customClaimModal.name.trim() === '') {
				this.customClaimModal.error = true
				this.customClaimModal.errorMsg = t('oidc', 'Name is required')
				return
			}
			if (this.customClaimModal.scope.trim() === '') {
				this.customClaimModal.error = true
				this.customClaimModal.errorMsg = t('oidc', 'Scope is required')
				return
			}
			if (this.customClaimModal.function.props.value == null) {
				this.customClaimModal.error = true
				this.customClaimModal.errorMsg = t('oidc', 'Function is required')
				return
			}
			if (!Array.isArray(this.customClaimModal.function.props.value)) {
				const tmpLabel = this.customClaimModal.function.props.value.label
				const tmpValue = this.customClaimModal.function.props.value.value
				this.customClaimModal.function.props.value = [
					{
						label: tmpLabel,
						value: tmpValue,
					},
				]
			}
			if (this.customClaimModal.parametersRequired && this.customClaimModal.parameters.trim() === '') {
				this.customClaimModal.error = true
				this.customClaimModal.errorMsg = t('oidc', 'Parameters are required for the selected function')
				return
			}

			const url = generateUrl('apps/oidc/api/v2/customClaim')

			const method = this.customClaimModal.isEdit ? 'patch' : 'post'

			axios({
				method,
				url,
				data: {
					clientUid: this.editClient.id,
					name: this.customClaimModal.name,
					scope: this.customClaimModal.scope,
					function: this.customClaimModal.function.props.value[0].value,
					parameter: this.customClaimModal.parameters,
				},
			}).then(response => {
				this.getCustomClaims()
				this.closeCustomClaimModal()
			}).catch(error_ => {
				this.customClaimModal.error = true
				this.customClaimModal.errorMsg = this.extractErrorMessage(error_)
			})
		},
		deleteCustomClaim() {
			this.customClaimModal.error = false

			axios.delete(generateUrl('apps/oidc/api/v2/customClaim?id={id}', { id: this.customClaimModal.id }))
				.then(response => {
					this.getCustomClaims()
					this.closeCustomClaimModal()
				}).catch(error_ => {
					this.customClaimModal.error = true
					this.customClaimModal.errorMsg = this.extractErrorMessage(error_)
				})
		},
		deleteRedirectUri(id) {
			this.error = false

			axios.delete(generateUrl('apps/oidc/api/v2/clients/redirect/{id}', { id }))
				.then((response) => {
					this.localClients.splice(0, this.localClients.length)
					for (const entry of response.data) {
						this.localClients.push(entry)
					}
					this.openOIDCTabEditClient(this.editClient.id)
					this.version += 1
				}).catch(error_ => {
					this.error = true
					this.errorMsg = this.extractErrorMessage(error_)
				})
		},
		addRedirectUri(id, uri) {
			this.error = false

			axios.post(
				generateUrl('apps/oidc/api/v2/clients/redirect'),
				{
					id,
					redirectUri: uri,
				},
			).then(response => {
				this.localClients.splice(0, this.localClients.length)
				for (const entry of response.data) {
					this.localClients.push(entry)
				}
				this.openOIDCTabEditClient(id)
				this.version += 1
			}).catch(error_ => {
				this.error = true
				this.errorMsg = this.extractErrorMessage(error_)
			})
		},
		deleteLogoutRedirectUri(id) {
			this.error = false

			axios.delete(generateUrl('apps/oidc/api/v2/logoutRedirect/{id}', { id }))
				.then((response) => {
					this.localLogoutRedirectUris = response.data
					this.version += 1
				}).catch(error_ => {
					this.error = true
					this.errorMsg = this.extractErrorMessage(error_)
				})
		},
		addLogoutRedirectUri() {
			this.error = false

			axios.post(
				generateUrl('apps/oidc/api/v2/logoutRedirect'),
				{
					redirectUri: this.newLogoutRedirectUri.redirectUri,
				},
			).then(response => {
				this.localLogoutRedirectUris = response.data
				this.newLogoutRedirectUri.redirectUri = ''
				this.version += 1
			}).catch(error_ => {
				this.error = true
				this.errorMsg = this.extractErrorMessage(error_)
			})
		},
		deleteClient() {
			const id = this.editClient.id
			axios.delete(generateUrl('apps/oidc/api/v2/clients/{id}', { id }))
				.then((response) => {
					this.localClients = this.localClients.filter(client => client.id !== id)
					this.version += 1
					this.openOIDCTabClients()
				}).catch(error_ => {
					this.error = true
					this.errorMsg = this.extractErrorMessage(error_)
					this.openOIDCTabEditClient(this.editClient.id, false)
				})
		},
		addClient() {
			axios.post(
				generateUrl('apps/oidc/api/v2/clients'),
				{
					name: this.newClient.name,
					redirectUri: this.newClient.redirectUri,
					signingAlg: this.newClient.signingAlg,
					type: this.newClient.type,
					flowType: this.newClient.flowType,
					tokenType: this.newClient.tokenType,
				},
			).then(response => {
				console.info('Client created successfully:', response.data)
				this.localClients.push(response.data)

				this.newClient.name = ''
				this.newClient.redirectUri = ''
				this.newClient.signingAlg = 'RS256'
				this.signingAlgs.props.value = []
				this.signingAlgs.props.value.push({
					label: 'RS256',
					value: 'RS256',
				})
				this.newClient.type = 'confidential'
				this.clientTypes.props.value = []
				this.clientTypes.props.value.push({
					label: t('oidc', 'Confidential'),
					value: 'confidential',
				})
				this.newClient.flowType = 'code'
				this.newClient.tokenType = ''
				this.openOIDCTabClients()
			}).catch(error_ => {
				this.error = true
				this.errorMsg = this.extractErrorMessage(error_)
			})
		},
		saveEditClient() {
			this.clearError()
			this.updateFlowTypes()
			this.updateTokenType()
			this.updateGroups()
			this.setAllowedScopes()
			this.setEmailRegex()
			this.setResourceUrl()
			if (this.error) {
				this.openOIDCTabEditClient(this.editClient.id, false)
			} else {
				this.openOIDCTabClients()
			}
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
		setProvideRefreshTokenAlways() {
			axios.post(
				generateUrl('apps/oidc/provideRefreshTokenAlways'),
				{
					provideRefreshTokenAlways: this.localProvideRefreshTokenAlways,
				}).then((response) => {
				this.localProvideRefreshTokenAlways = response.data.provide_refresh_token_always
			})
		},
		regenerateKeys() {
			axios.post(
				generateUrl('apps/oidc/api/v2/genKeys'),
				{}).then((response) => {
				this.localPublicKey = response.data.public_key
			})
		},
		async getCustomClaims() {
			await axios.get(
				generateUrl('apps/oidc/api/v2/customClaim?clientUid={clientUid}', { clientUid: this.editClient.id }),
			).then(response => {
				this.editClient.customClaims.splice(0, this.editClient.customClaims.length)
				for (const entry of response.data) {
					this.editClient.customClaims.push(entry)
				}
			}).catch(error_ => {
				this.error = true
				this.errorMsg = this.extractErrorMessage(error_)
			})
		},
		async updateGroups() {
			const id = this.editClient.id
			await axios.patch(
				generateUrl('apps/oidc/clients/groups/{id}', { id }),
				{
					id,
					groups: this.editClient.groupData.props.value,
				},
			).then(response => {
				// Update local state
				const clientIndex = this.localClients.findIndex(c => c.id === id)
				if (clientIndex !== -1) {
					this.localClients[clientIndex].groups = this.editClient.groupData.props.value
					this.version += 1
				}
			}).catch(error_ => {
				this.error = true
				this.errorMsg = this.extractErrorMessage(error_)
			})
		},
		async updateFlowTypes() {
			const id = this.editClient.id
			await axios.patch(
				generateUrl('apps/oidc/clients/flows/{id}', { id }),
				{
					id,
					flowType: this.editClient.flowData.props.value[0].value,
				},
			).then(response => {
				// Update local clients list with changed data
				this.localClients = this.localClients.filter(client => client.id !== id)
				this.localClients[0].flowType = this.editClient.flowData.props.value[0].value
				if (this.editClient.flowData.props.value[0].value === 'code') {
					this.localClients[0].flowTypeLabel = t('oidc', 'Code Authorization Flow')
				} else {
					this.localClients[0].flowTypeLabel = t('oidc', 'Code & Implicit Authorization Flow')
				}
				this.version += 1
			}).catch(error_ => {
				this.error = true
				this.errorMsg = this.extractErrorMessage(error_)
			})
		},
		async updateTokenType() {
			const id = this.editClient.id

			await axios.patch(
				generateUrl('apps/oidc/clients/token_type/{id}', { id }),
				{
					id,
					tokenType: this.editClient.tokenType,
				},
			).then(response => {
				// Update local state
				const clientIndex = this.localClients.findIndex(c => c.id === id)
				if (clientIndex !== -1) {
					this.localClients[clientIndex].tokenType = this.editClient.tokenType
					this.version += 1
				}
			}).catch(error_ => {
				this.error = true
				this.errorMsg = this.extractErrorMessage(error_)
			})
		},
		async setAllowedScopes() {
			const id = this.editClient.id

			await axios.patch(
				generateUrl('apps/oidc/clients/allowed_scopes/{id}', { id }),
				{
					id,
					allowedScopes: this.editClient.allowedScopes,
				},
			).then(response => {
				// Update local clients list with response data
				this.localClients.splice(0, this.localClients.length)
				for (const entry of response.data) {
					this.localClients.push(entry)
				}
				this.version += 1
			}).catch(error_ => {
				this.error = true
				this.errorMsg = this.extractErrorMessage(error_)
			})
		},
		async setEmailRegex() {
			const id = this.editClient.id

			await axios.patch(
				generateUrl('apps/oidc/clients/email_regex/{id}', { id }),
				{
					id,
					emailRegex: this.editClient.emailRegex,
				},
			).then(response => {
				// Update local state
				const clientIndex = this.localClients.findIndex(c => c.id === id)
				if (clientIndex !== -1) {
					this.localClients[clientIndex].emailRegex = this.editClient.emailRegex
					this.version += 1
				}
			}).catch(error_ => {
				this.error = true
				this.errorMsg = this.extractErrorMessage(error_)
			})
		},
		async setResourceUrl() {
			const id = this.editClient.id

			await axios.patch(
				generateUrl('apps/oidc/clients/resource_url/{id}', { id }),
				{
					id,
					resourceUrl: this.editClient.resourceUrl,
				},
			).then(response => {
				// Update local state
				const clientIndex = this.localClients.findIndex(c => c.id === id)
				if (clientIndex !== -1) {
					this.localClients[clientIndex].resourceUrl = this.editClient.resourceUrl
					this.version += 1
				}
			}).catch(error_ => {
				this.error = true
				this.errorMsg = this.extractErrorMessage(error_)
			})
		},
		newClientUpdateSigningAlg() {
			this.newClient.signingAlg = 'RS256'
			if (this.signingAlgs.props.value != null && this.signingAlgs.props.value.value != null && this.signingAlgs.props.value.value === 'HS256') {
				this.newClient.signingAlg = 'HS256'
			}
			if (this.newClient.signingAlg === 'RS256') {
				this.signingAlgs.props.value = []
				this.signingAlgs.props.value.push({
					label: 'RS256',
					value: 'RS256',
				})
			} else {
				this.signingAlgs.props.value = []
				this.signingAlgs.props.value.push({
					label: 'HS256',
					value: 'HS256',
				})
			}
		},
		newClientUpdateType() {
			this.newClient.type = 'confidential'
			if (this.clientTypes.props.value != null && this.clientTypes.props.value.value != null && this.clientTypes.props.value.value === 'public') {
				this.newClient.type = 'public'
			}
			if (this.newClient.type === 'confidential') {
				this.clientTypes.props.value = []
				this.clientTypes.props.value.push({
					label: t('oidc', 'Confidential'),
					value: 'confidential',
				})
			} else {
				this.clientTypes.props.value = []
				this.clientTypes.props.value.push({
					label: t('oidc', 'Public'),
					value: 'public',
				})
			}
		},
		editClientUpdateFlowType() {
			let value = 'code'
			if (this.editClient.flowData.props.value != null && this.editClient.flowData.props.value.value != null && this.editClient.flowData.props.value.value === 'code id_token') {
				value = 'code id_token'
			}
			this.editClient.flowData.props.value = []
			if (value === 'code') {
				this.editClient.flowData.props.value.push({
					label: t('oidc', 'Code Authorization Flow'),
					value: 'code',
				})
			} else {
				this.editClient.flowData.props.value.push({
					label: t('oidc', 'Code & Implicit Authorization Flow'),
					value: 'code id_token',
				})
			}
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
		width: calc(100% - 24px);
	}

	#oidc .settings-section h2.settings-section__name {
		font-size: 20px !important;
		font-weight: bold !important;
	}

	#oidc .container {
		display: flex;
		flex-direction: column;
		gap: 5px;
		max-width: calc(100% - 24px);
		width: 740px;
	}

	#oidc .container-inner {
		display: flex;
		flex-direction: column;
		gap: 0px;
		width: 100%;
	}

	.modal-container__content {
		padding: 12px;
	}

	#oidc .grid-inner-2 {
		display: grid;
		grid-template-columns: 7fr 1fr;
		width: 100%;
		align-items: end;
	}

	pre {
		overflow-x: auto;
		max-width: 100%;
		padding: 1rem;
		white-space: pre-wrap;
		word-break: break-word;
	}

	code {
		font-family: monospace;
		font-size: small;
	}

</style>
