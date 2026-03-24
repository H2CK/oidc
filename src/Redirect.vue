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
		// Build authorize URL preserving OIDC parameters passed from the
		// server template. These parameters survive SAML session
		// regeneration because they travel via URL, not the PHP session.
		const el = document.getElementById('oidc-redirect')
		const params = new URLSearchParams()
		const keys = [
			'client_id', 'state', 'response_type', 'redirect_uri',
			'scope', 'nonce', 'resource', 'code_challenge', 'code_challenge_method',
		]
		keys.forEach(key => {
			const value = el?.getAttribute('data-' + key.replace(/_/g, '-'))
			if (value) {
				params.append(key, value)
			}
		})
		const query = params.toString()
		window.location.replace(generateUrl('apps/oidc/authorize') + (query ? '?' + query : ''))
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
