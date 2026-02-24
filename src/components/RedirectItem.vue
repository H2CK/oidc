<!--
  - SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
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
		grid-template-columns: minmax(0, 1fr) 30px;
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
