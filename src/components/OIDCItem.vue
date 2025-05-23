<!--
  - SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<tr>
		<td>
			<table class="inline">
				<tbody>
					<tr>
						<td>{{ t('oidc', 'Name') }}</td>
						<td>{{ name }}</td>
					</tr>
					<tr>
						<td>{{ t('oidc', 'Redirection URI') }}</td>
						<td>
							<table>
								<tr v-for="redirectUri in redirectUris" :key="redirectUri.id">
									<td>{{ redirectUri.redirect_uri }}</td>
									<td class="action-column">
										<span><a class="icon-delete has-tooltip" :title="t('oidc', 'Delete')" @click="$emit('deleteredirect', redirectUri.id)" /></span>
									</td>
								</tr>
							</table>
							<form @submit.prevent="addRedirect">
								<input v-model="addRedirectUri"
									type="url"
									name="redirectUri"
									:placeholder="t('oidc', 'Redirection URI')">
								<input type="submit" class="button" :value="t('oidc', 'Add')">
							</form>
						</td>
					</tr>
					<tr>
						<td>{{ t('oidc', 'Client Identifier') }}</td>
						<td><code>{{ clientId }}</code></td>
					</tr>
					<tr>
						<td>{{ t('oidc', 'Secret') }}</td>
						<td v-if="!isPublic">
							<code>{{ renderedSecret }}</code><a class="icon-toggle has-tooltip" :title="t('oidc', 'Show client secret')" @click="toggleSecret" />
						</td>
						<td v-if="isPublic">
							<code>{{ t('oidc', '-- NONE --') }}</code>
						</td>
					</tr>
					<tr>
						<td>{{ t('oidc', 'Signing Algorithm') }}</td>
						<td><code>{{ signingAlg }}</code></td>
					</tr>
					<tr>
						<td>{{ t('oidc', 'Type') }}</td>
						<td><code>{{ t('oidc', type) }}</code></td>
					</tr>
					<tr>
						<td>{{ t('oidc', 'Flows') }}</td>
						<td>
							<div class="oidc_flow_container">
								<NcSelect v-bind="flowData.props"
									v-model="flowData.props.value"
									:input-label="t('oidc', 'Flows allowed to be used with the client.')"
									:placeholder="t('oidc', 'Flows allowed to be used with the client.')"
									:no-wrap="true"
									class="nc_select"
									@update:modelValue="updateFlowTypes" />
							</div>
						</td>
					</tr>
					<tr>
						<td>{{ t('oidc', 'Access Token Type') }}</td>
						<td>
							<div class="oidc_token_type_container">
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
						</td>
					</tr>
					<tr>
						<td>{{ t('oidc', 'Limited to Groups') }}</td>
						<td>
							<div class="oidc_group_container">
								<NcSelect v-bind="groupData.props"
									v-model="groupData.props.value"
									:input-label="t('oidc', 'Only users in one of the following groups are allowed to use the client.')"
									:placeholder="t('oidc', 'Groups allowed to use the client.')"
									:no-wrap="false"
									class="nc_select"
									@update:modelValue="updateGroups" />
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</td>
		<td class="action-column">
			<span><a class="icon-delete has-tooltip" :title="t('oidc', 'Delete')" @click="$emit('delete', id)" /></span>
		</td>
	</tr>
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { t } from '@nextcloud/l10n'

export default {
	name: 'OIDCItem',
	components: {
		NcSelect,
		NcCheckboxRadioSwitch,
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

	td code {
		display: inline-block;
		vertical-align: middle;
	}

	table.inline td {
		border: none;
		padding: 5px;
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

</style>
