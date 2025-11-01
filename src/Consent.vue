<!--
  - SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div id="oidc-consent-container">
		<div class="consent-box">
			<h2>{{ t('oidc', 'Application Authorization Request') }}</h2>

			<p class="consent-intro">
				<strong>{{ clientName }}</strong> {{ t('oidc', 'is requesting access to your account.') }}
			</p>

			<div class="consent-scopes">
				<h3>{{ t('oidc', 'This application will be able to:') }}</h3>

				<div class="scope-list">
					<div v-for="scope in scopes" :key="scope.name" class="scope-item">
						<input
							:id="'scope-' + scope.name"
							v-model="selectedScopes"
							type="checkbox"
							:value="scope.name"
							:disabled="scope.name === 'openid'"
							class="checkbox">
						<label :for="'scope-' + scope.name" class="scope-label">
							<span class="scope-title">{{ scope.label }}</span>
							<span class="scope-description">{{ scope.description }}</span>
						</label>
					</div>
				</div>
			</div>

			<div class="consent-actions">
				<button
					class="button secondary"
					@click="handleDeny">
					{{ t('oidc', 'Deny') }}
				</button>
				<button
					class="button primary"
					:disabled="selectedScopes.length === 0"
					@click="handleGrant">
					{{ t('oidc', 'Allow') }}
				</button>
			</div>

			<p class="consent-note">
				{{ t('oidc', 'You can revoke this access at any time from your account settings.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'Consent',
	props: {
		clientName: {
			type: String,
			required: true,
		},
		requestedScopes: {
			type: String,
			required: true,
		},
		clientId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			scopes: [],
			selectedScopes: [],
		}
	},
	created() {
		this.scopes = this.parseScopes(this.requestedScopes)
		// Pre-select all scopes by default (user can deselect)
		this.selectedScopes = this.scopes.map(s => s.name)
	},
	methods: {
		t,
		parseScopes(scopeString) {
			const scopeDescriptions = {
				openid: {
					label: t('oidc', 'Basic authentication'),
					description: t('oidc', 'Verify your identity (required)'),
				},
				profile: {
					label: t('oidc', 'Profile information'),
					description: t('oidc', 'Access your name, username, profile picture, and quota'),
				},
				email: {
					label: t('oidc', 'Email address'),
					description: t('oidc', 'Access your email address and verification status'),
				},
				roles: {
					label: t('oidc', 'Group memberships'),
					description: t('oidc', 'Access your Nextcloud groups and roles'),
				},
				groups: {
					label: t('oidc', 'Group memberships'),
					description: t('oidc', 'Access your Nextcloud group information'),
				},
				offline_access: {
					label: t('oidc', 'Access when you\'re away'),
					description: t('oidc', 'Allow this app to access your data even when you\'re not signed in'),
				},
			}

			return scopeString.split(' ').filter(s => s.trim() !== '').map(scope => ({
				name: scope,
				label: scopeDescriptions[scope]?.label || scope,
				description: scopeDescriptions[scope]?.description || '',
			}))
		},
		async handleGrant() {
			const selectedScopesString = this.selectedScopes.join(' ')

			try {
				const response = await fetch(generateUrl('/apps/oidc/consent/grant'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
						requesttoken: OC.requestToken,
					},
					body: 'scopes=' + encodeURIComponent(selectedScopesString),
					redirect: 'follow',
				})

				// Follow redirect
				if (response.redirected) {
					window.location.href = response.url
				} else {
					// Manually redirect to authorize endpoint
					window.location.href = generateUrl('/apps/oidc/authorize')
				}
			} catch (error) {
				console.error('Error granting consent:', error)
				// Fallback: redirect to authorize endpoint
				window.location.href = generateUrl('/apps/oidc/authorize')
			}
		},
		async handleDeny() {
			try {
				const response = await fetch(generateUrl('/apps/oidc/consent/deny'), {
					method: 'POST',
					headers: {
						requesttoken: OC.requestToken,
					},
					redirect: 'follow',
				})

				// Follow redirect
				if (response.redirected) {
					window.location.href = response.url
				} else {
					// Fallback: redirect to base URL
					window.location.href = generateUrl('/')
				}
			} catch (error) {
				console.error('Error denying consent:', error)
				window.location.href = generateUrl('/')
			}
		},
	},
}
</script>

<style>
/* Global styles to override Nextcloud page background */
body:has(#oidc-consent) #content-wrapper,
body:has(#oidc-consent) #content,
body:has(#oidc-consent) .content-wrapper,
body:has(#oidc-consent) {
	background: transparent !important;
}
</style>

<style scoped>
#oidc-consent-container {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	justify-content: center;
	align-items: center;
	padding: 20px;
	box-sizing: border-box;
	background: rgba(0, 0, 0, 0.5);
	backdrop-filter: blur(3px);
	z-index: 2000;
}

.consent-box {
	max-width: 600px;
	width: 100%;
	max-height: calc(100vh - 40px);
	display: flex;
	flex-direction: column;
	padding: 30px;
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	box-sizing: border-box;
}

.consent-box h2 {
	margin-top: 0;
	margin-bottom: 20px;
	font-size: 24px;
	text-align: center;
	flex-shrink: 0;
}

.consent-intro {
	margin-bottom: 20px;
	text-align: center;
	font-size: 16px;
	line-height: 1.5;
	flex-shrink: 0;
}

.consent-scopes {
	margin-bottom: 20px;
	flex: 1;
	min-height: 0;
	display: flex;
	flex-direction: column;
}

.consent-scopes h3 {
	font-size: 18px;
	margin-bottom: 15px;
	flex-shrink: 0;
}

.scope-list {
	display: flex;
	flex-direction: column;
	gap: 15px;
	overflow-y: auto;
	padding-right: 5px;
	flex: 1;
	min-height: 0;
}

.scope-item {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.scope-item:hover {
	background: var(--color-background-dark);
}

.scope-item input[type="checkbox"] {
	margin-top: 3px;
	flex-shrink: 0;
}

.scope-label {
	display: flex;
	flex-direction: column;
	gap: 5px;
	cursor: pointer;
	flex-grow: 1;
}

.scope-title {
	font-weight: bold;
	font-size: 14px;
}

.scope-description {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.consent-actions {
	display: flex;
	justify-content: space-between;
	gap: 15px;
	margin-bottom: 20px;
	flex-shrink: 0;
}

.consent-actions button {
	flex: 1;
	padding: 12px 24px;
	font-size: 16px;
	border-radius: var(--border-radius);
	cursor: pointer;
	border: none;
	transition: background-color 0.2s;
}

.consent-actions button.primary {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.consent-actions button.primary:hover:not(:disabled) {
	background: var(--color-primary-element-light);
}

.consent-actions button.primary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.consent-actions button.secondary {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
}

.consent-actions button.secondary:hover {
	background: var(--color-background-darker);
}

.consent-note {
	text-align: center;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
	flex-shrink: 0;
}
</style>
