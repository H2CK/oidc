<!--
  - SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div id="oidc-redirect">
		<h2 style="text-align: center;">
			{{ t('oidc', 'OpenID Connect Redirect') }}
		</h2>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'Redirect',
	created() {
		window.location.replace(generateUrl('apps/oidc/authorize'))
	},
	methods: {
		t,
	},
}
</script>
<style scoped>
	table {
		max-width: 800px;
	}
</style>
