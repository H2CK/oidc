<!--
  - SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div>
		<NcSettingsSection :name="t('oidc', 'OpenID Connect Provider')"
			:description="
				t(
					'oidc',
					'OpenID Connect allows you to log in to external services with your {instanceName} user account.',
					{ instanceName: oc.theme.name }
				)
			">
			<span v-if="error" class="msg error">{{ errorMsg }}</span>

			<div v-if="localAllowUserSettings == 'no'">
				<p>
					{{ t('oidc', 'All settings for the login at other services are managed by your administrator.') }}
				</p>
			</div>

			<div v-if="localAllowUserSettings != 'no'">
				<h4>
					{{ t("oidc", "Restrict Personal Information") }}
				</h4>
				<NcSelect v-bind="userDataRestriction.props"
					v-model="userDataRestriction.props.value"
					:no-wrap="false"
					:input-label="t('oidc', 'Removed information from ID token and userinfo endpoint')"
					:placeholder="t('oidc', 'Select information to be omitted')"
					class="nc_select"
					@update:modelValue="updateRestrictUserInformation" />
			</div>

			<AuthorizedApps />
		</NcSettingsSection>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import AuthorizedApps from './components/AuthorizedApps.vue'

export default {
	name: 'AppPersonal',
	components: {
		NcSettingsSection,
		NcSelect,
		AuthorizedApps,
	},
	props: {
		allowUserSettings: {
			type: String,
			required: true,
		},
		restrictUserInformation: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			localAllowUserSettings: this.allowUserSettings,
			error: false,
			errorMsg: '',
			version: 0,
			userDataRestriction: {
				props: {
					multiple: true,
					keepOpen: true,
					options: [
						{
							label: t('oidc', 'Avatar'),
							value: 'avatar',
						},
						{
							label: t('oidc', 'Address'),
							value: 'address',
						},
						{
							label: t('oidc', 'Phone'),
							value: 'phone',
						},
						{
							label: t('oidc', 'Website'),
							value: 'website',
						},
					],
					value: this.generateRestrictUserInformationProperties(
						this.restrictUserInformation,
					),
				},
			},
		}
	},
	computed: {
		oc() {
			return window.OC
		},
	},
	methods: {
		t,
		updateRestrictUserInformation() {
			let tmpStr = ''
			if (this.userDataRestriction.props.value.length > 0) {
				for (const element of this.userDataRestriction.props.value) {
					tmpStr = tmpStr + element.value + ' '
				}
				tmpStr.trim()
			} else {
				tmpStr = 'no'
			}
			axios
				.post(generateUrl('apps/oidc/user/restrictUserInformation'), {
					restrictUserInformation: tmpStr,
				})
				.then((response) => {
					this.userDataRestriction.props.value = this.generateRestrictUserInformationProperties(response.data.restrict_user_information)
				})
		},
		generateRestrictUserInformationProperties(conf) {
			const tmpArr = conf.split(' ')
			const resultPropValue = []
			for (const element of tmpArr) {
				switch (element) {
				case 'avatar':
					resultPropValue.push({
						label: t('oidc', 'Avatar'),
						value: element,
					})
					break

				case 'address':
					resultPropValue.push({
						label: t('oidc', 'Address'),
						value: element,
					})
					break

				case 'phone':
					resultPropValue.push({
						label: t('oidc', 'Phone'),
						value: element,
					})
					break

				case 'website':
					resultPropValue.push({
						label: t('oidc', 'Website'),
						value: element,
					})
					break

				default:
					break
				}
			}
			return resultPropValue
		},
	},
}
</script>
<style>
#oidc-personal .settings-section {
  width: 95%;
}

#oidc-personal .settings-section h2.settings-section__name {
  font-size: 20px !important;
  font-weight: bold !important;
}
</style>
