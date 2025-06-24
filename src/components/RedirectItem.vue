<!--
  - SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="grid-inner">
		<div class="label">
			{{ redirectUri }}
		</div>
		<div class="action-column">
			<span><a class="icon-delete has-tooltip" :title="t('oidc', 'Delete')" @click="$emit('delete', id)" /></span>
		</div>
	</div>
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

	#oidc .grid-inner {
		display: grid;
		grid-template-columns: 1fr 30px;
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

</style>
