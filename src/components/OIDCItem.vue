<!--
  - SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<NcFormBoxButton :label="name"
		@click="details()">
		<template #icon>
			<NcIconSvgWrapper :path="mdiArrowRight" inline />
		</template>
	</NcFormBoxButton>
</template>

<script>
import NcFormBoxButton from '@nextcloud/vue/components/NcFormBoxButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiArrowRight } from '@mdi/js'
import { t } from '@nextcloud/l10n'

export default {
	name: 'OIDCItem',
	components: {
		NcFormBoxButton,
		NcIconSvgWrapper,
	},
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
			mdiArrowRight,
		}
	},
	computed: {
	},
	methods: {
		t,
		details() {
			this.$emit('editclient', this.id)
		},
	},
}
</script>
