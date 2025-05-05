<!--
  - @copyright Copyright (c) 2022-2025 Thorsten Jagel <dev@jagel.net>
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
