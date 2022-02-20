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
	<tr>
		<td>
			<table class="inline">
				<tr>
					<td>{{ t('oidc', 'Name') }}</td>
					<td>{{ name }}</td>
				</tr>
				<tr>
					<td>{{ t('oidc', 'Redirection URI') }}</td>
					<td>{{ redirectUri }}</td>
				</tr>
				<tr>
					<td>{{ t('oidc', 'Client Identifier') }}</td>
					<td><code>{{ clientId }}</code></td>
				</tr>
				<tr>
					<td>{{ t('oidc', 'Secret') }}</td>
					<td><code>{{ renderedSecret }}</code><a class="icon-toggle has-tooltip" :title="t('oidc', 'Show client secret')" @click="toggleSecret" /></td>
				</tr>
			</table>
		</td>
		<td class="action-column">
			<span><a class="icon-delete has-tooltip" :title="t('oidc', 'Delete')" @click="$emit('delete', id)" /></span>
		</td>
	</tr>
</template>

<script>
export default {
	name: 'OIDCItem',
	props: {
		client: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			id: this.client.id,
			name: this.client.name,
			redirectUri: this.client.redirectUri,
			clientId: this.client.clientId,
			clientSecret: this.client.clientSecret,
			renderSecret: false,
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
	},
	methods: {
		toggleSecret() {
			this.renderSecret = !this.renderSecret
		},
	},
}
</script>

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
</style>
