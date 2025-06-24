<!--
  - SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="grid">
		<div class="label">
			{{ t('oidc', 'Name') }}
		</div>
		<div class="data">
			{{ name }}
		</div>
		<div style="display: flex; justify-content: flex-end">
			<span><a class="icon-delete has-tooltip" :title="t('oidc', 'Delete')" @click="$emit('delete', id)" /></span>
		</div>
		<div class="label">
			{{ t('oidc', 'Redirection URI') }}
		</div>
		<div class="data">
			<div v-for="redirectUri in redirectUris" :key="redirectUri.id" class="grid-inner">
				<div class="data-inner">
					{{ redirectUri.redirect_uri }}
				</div>
				<div class="action-column">
					<span><a class="icon-delete has-tooltip" :title="t('oidc', 'Delete')" @click="$emit('deleteredirect', redirectUri.id)" /></span>
				</div>
			</div>
			<div class="grid-inner-2">
				<NcTextField v-model="addRedirectUri"
					style="width: 100%"
					type="url"
					name="redirectUri"
					:placeholder="t('oidc', 'Redirection URI')" />
				<NcButton :aria-label="t('oidc', 'Add Redirection URI')"
					:text="t('oidc', 'Add')"
					style="width: 100%"
					variant="secondary"
					@click="addRedirect" />
			</div>
		</div>
		<div />
		<div class="label">
			{{ t('oidc', 'Client Identifier') }}
		</div>
		<div class="data">
			<code>{{ clientId }}</code>
		</div>
		<div />
		<div class="label">
			{{ t('oidc', 'Secret') }}
		</div>
		<div v-if="!isPublic" class="data">
			<code>{{ renderedSecret }}</code><a class="icon-toggle has-tooltip" :title="t('oidc', 'Show client secret')" @click="toggleSecret" />
		</div>
		<div v-if="isPublic" class="data">
			<code>{{ t('oidc', '-- NONE --') }}</code>
		</div>
		<div />
		<div class="label">
			{{ t('oidc', 'Signing Algorithm') }}
		</div>
		<div class="data">
			<code>{{ signingAlg }}</code>
		</div>
		<div />
		<div class="label">
			{{ t('oidc', 'Type') }}
		</div>
		<div class="data">
			<code>{{ t('oidc', type) }}</code>
		</div>
		<div />
		<div class="label">
			{{ t('oidc', 'Flows') }}
		</div>
		<div class="oidc_flow_container data">
			<NcSelect v-bind="flowData.props"
				v-model="flowData.props.value"
				:input-label="t('oidc', 'Flows allowed to be used with the client.')"
				:placeholder="t('oidc', 'Flows allowed to be used with the client.')"
				:no-wrap="true"
				class="nc_select"
				@update:modelValue="updateFlowTypes" />
		</div>
		<div />
		<div class="label">
			{{ t('oidc', 'Access Token Type') }}
		</div>
		<div class="oidc_token_type_container data">
			<NcCheckboxRadioSwitch v-model="tokenType"
				value="opaque"
				name="token_type"
				type="radio"
				@update:modelValue="updateTokenType">
				{{ t('oidc', 'Opaque Access Token') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="tokenType"
				value="jwt"
				name="token_type"
				type="radio"
				@update:modelValue="updateTokenType">
				{{ t('oidc', 'JWT Access Token (RFC9068)') }}
			</NcCheckboxRadioSwitch>
		</div>
		<div />
		<div class="label">
			{{ t('oidc', 'Limited to Groups') }}
		</div>
		<div class="oidc_group_container data">
			<NcSelect v-bind="groupData.props"
				v-model="groupData.props.value"
				:input-label="t('oidc', 'Only users in one of the following groups are allowed to use the client.')"
				:placeholder="t('oidc', 'Groups allowed to use the client.')"
				:no-wrap="false"
				class="nc_select"
				@update:modelValue="updateGroups" />
		</div>
		<div />
		<div class="label">
			{{ t('oidc', 'Allowed Scopes') }}
		</div>
		<div class="data">
			<div class="grid-inner-2">
				<NcTextField v-model="allowedScopes"
					style="width: 100%"
					:placeholder="t('oidc', 'Allowed Scopes')" />
				<NcButton :aria-label="t('oidc', 'Save allowed scopes')"
					:text="t('oidc', 'Save')"
					style="width: 100%"
					variant="secondary"
					@click="saveAllowedScopes" />
				<div class="helper_text">
					{{ t('oidc', 'Define the allowed scopes for the client separated by a whitespace, e.g. openid profile roles. Do not enter any value to allow all scopes.') }}
				</div>
			</div>
		</div>
		<div />
		<div class="label">
			<label for="emailRegex">
				{{ t('oidc', 'Email Selection') }}
			</label>
		</div>
		<div class="data">
			<div class="grid-inner-2">
				<NcTextField id="emailRegex"
					v-model="emailRegex"
					:label-outside="true"
					style="width: 100%"
					:placeholder="t('oidc', 'Email Selection')"
					type="text" />
				<NcButton :aria-label="t('oidc', 'Save email selection regex')"
					:text="t('oidc', 'Save')"
					style="width: 100%"
					variant="secondary"
					@click="saveEmailRegex" />
				<div class="helper_text">
					{{ t('oidc', 'Usually the primary email address is used during OpenID control flows. If you wish to use other email adresses (defined as secondary email address in personal settings) you could define a regular expression for selecting the used email address. E.g. .*@domain.tld') }}
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcButton from '@nextcloud/vue/components/NcButton'
import { t } from '@nextcloud/l10n'

export default {
	name: 'OIDCItem',
	components: {
		NcSelect,
		NcCheckboxRadioSwitch,
		NcTextField,
		NcButton,
	},
	props: {
		client: {
			type: Object,
			required: true,
		},
		groups: {
			type: Array,
			required: true,
		},
	},
	data() {
		return {
			id: this.client.id,
			name: this.client.name,
			redirectUris: this.client.redirectUris,
			clientId: this.client.clientId,
			clientSecret: this.client.clientSecret,
			signingAlg: this.client.signingAlg,
			type: this.client.type,
			renderSecret: false,
			addRedirectUri: '',
			tokenType: this.client.tokenType,
			allowedScopes: this.client.allowedScopes,
			emailRegex: this.client.emailRegex,
			flowData: {
				props: {
					inputId: this.client.id + '-flow-select',
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
					value: {
						label: this.client.flowTypeLabel,
						value: this.client.flowType,
					},
				},
			},
			groupData: {
				props: {
					inputId: this.client.id + '-group-select',
					multiple: true,
					closeOnSelect: true,
					options: this.groups,
					value: this.client.groups,
				},
			},
		}
	},
	computed: {
		renderedSecret() {
			if (this.renderSecret) {
				return this.clientSecret
			} else {
				return '****'
			}
		},
		isPublic() {
			return this.type === 'public'
		},
	},
	methods: {
		t,
		toggleSecret() {
			this.renderSecret = !this.renderSecret
		},
		addRedirect() {
			this.$emit('addredirect', this.id, this.addRedirectUri)
			this.addRedirectUri = ''
		},
		updateGroups() {
			this.$emit('updategroups', this.id, this.groupData.props.value)
		},
		updateFlowTypes() {
			this.$emit('updateflowtypes', this.id, this.flowData.props.value)
		},
		updateTokenType() {
			this.$emit('updatetokentype', this.id, this.tokenType)
		},
		saveAllowedScopes() {
			this.$emit('saveallowedscopes', this.id, this.allowedScopes)
		},
		saveEmailRegex() {
			this.$emit('saveemailregex', this.id, this.emailRegex)
		},
	},
}
</script>

<style>
	.vs__deselect {
		padding: 0 !important;
		border: 0 !important;
		margin-left: 4px !important;
		min-height: 24px !important;
	}

	.vs__clear {
		padding: 0 !important;
		border: 0 !important;
		margin-left: 4px !important;
		min-height: 24px !important;
		background-color: transparent !important;
	}

	.vs__search {
		border-width: 0px !important;
	}

	.vs__selected {
		min-height: 32px !important;
	}

</style>

<style scoped>
	.icon-toggle,
	.icon-delete {
		display: inline-block;
		width: 16px;
		height: 16px;
		padding: 10px;
		vertical-align: middle;
	}

	.oidc_group_container {
		display: flex;
		flex-direction: column;
		gap: 2px 0;
	}

	.oidc_flow_container {
		display: flex;
		flex-direction: column;
		gap: 2px 0;
	}

	#oidc .grid {
		display: grid;
		grid-template-columns: 1fr 3fr 30px;
		border: 2px solid var(--color-main-text);
		margin-bottom: 5px;
		padding: 0px;
		border-radius: 5px;
	}

	#oidc .grid-inner {
		display: grid;
		grid-template-columns: 1fr 30px;
	}

	#oidc .grid-inner-2 {
		display: grid;
		grid-template-columns: 7fr 1fr;
	}

	#oidc .label {
		padding: 5px;
	}

	#oidc .data {
		padding: 5px;
	}

	#oidc .data-inner {
		padding-top: 5px;
		padding-bottom: 5px;
		padding-right: 5px;
	}

	#oidc .helper_text {
		padding-block: 4px;
		padding-inline: var(--border-radius-large);
		color: var(--color-text-maxcontrast);
	}

</style>
