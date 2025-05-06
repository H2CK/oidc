<!--
  - SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
  - SPDX-License-Identifier: AGPL-3.0-or-later
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
import { t } from '@nextcloud/l10n'

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
	methods: {
		t,
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
