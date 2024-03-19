<!--
  - @copyright Copyright (c) 2022-2024 Thorsten Jagel <dev@jagel.net>
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
	<tr>
		<td>
			<table class="inline">
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
								@input="updateFlowTypes" />
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
								@input="updateGroups" />
						</div>
					</td>
				</tr>
			</table>
		</td>
		<td class="action-column">
			<span><a class="icon-delete has-tooltip" :title="t('oidc', 'Delete')" @click="$emit('delete', id)" /></span>
		</td>
	</tr>
</template>

<script>
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'

export default {
	name: 'OIDCItem',
	components: {
		NcSelect,
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
			if (this.type === 'public') return true
			return false
		},
	},
	methods: {
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
