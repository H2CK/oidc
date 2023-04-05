<!--
  - @copyright Copyright (c) 2022-2023 Thorsten Jagel <dev@jagel.net>
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
			{{ redirectUri }}
		</td>
		<td class="action-column" style="text-align: right;">
			<span><a class="icon-delete has-tooltip" :title="t('oidc', 'Delete')" @click="$emit('delete', id)" /></span>
		</td>
	</tr>
</template>

<script>

export default {
	name: 'RedirectItem',
	props: {
		redirectUri: {
			type: String,
			required: true,
		},
		id: {
			type: Number,
			required: true,
		},
	},
	data() {
		return {
		}
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
