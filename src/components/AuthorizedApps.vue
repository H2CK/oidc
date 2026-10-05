<!--
  - SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="authorized-apps">
		<h3>{{ t('oidc', 'Authorized Applications') }}</h3>
		<div v-if="notification" role="status" aria-live="polite">
			<NcNoteCard :type="notification.type" :text="notification.text" />
		</div>
		<p class="description">
			<span v-if="allowUserSettings === 'no'">
				{{ t('oidc', 'These applications have access to your account. Access is managed by your administrator.') }}
			</span>
			<span v-else>
				{{ t('oidc', 'These applications have access to your account. You can revoke access at any time.') }}
			</span>
		</p>

		<div v-if="loading" class="loading">
			<span class="icon-loading-small"></span>
			{{ t('oidc', 'Loading...') }}
		</div>

		<div v-else-if="consents.length === 0" class="empty-content">
			<div class="icon-checkmark"></div>
			<p>{{ t('oidc', 'No authorized applications') }}</p>
		</div>

		<div v-else class="consents-list">
			<div v-for="consent in consents" :key="consent.id" class="consent-item">
				<div class="consent-info">
					<h4>{{ consent.clientName }}</h4>
					<p class="client-id">
						<strong>{{ t('oidc', 'Client ID:') }}</strong>
						<code>{{ consent.clientIdentifier }}</code>
					</p>
					<div class="scopes">
						<strong>{{ t('oidc', 'Permissions:') }}</strong>
						<div class="scope-badges">
							<span
								v-for="scope in getAllowedScopes(consent)"
								:key="scope"
								class="scope-badge"
								:class="{
									active: isScopeGranted(consent, scope),
									inactive: !isScopeGranted(consent, scope),
								}">
								{{ scope }}
							</span>
						</div>
					</div>
					<p class="date">
						{{ t('oidc', 'Authorized on:') }} {{ formatDate(consent.createdAt) }}
					</p>
					<p v-if="consent.expiresAt" class="date">
						{{ t('oidc', 'Approval expires on: {date}', { date: formatDate(consent.expiresAt) }) }}
					</p>
					<div v-if="editingClient === consent.clientId" class="scope-editor">
						<label v-for="scope in getRequestedScopes(consent)" :key="scope">
							<input v-model="selectedScopes" type="checkbox" :value="scope" :disabled="scope === 'openid' || savingScopes">
							{{ scope }}
						</label>
						<button :disabled="savingScopes" @click="saveScopes(consent)">{{ t('oidc', 'Save permissions') }}</button>
						<button :disabled="savingScopes" @click="editingClient = null">{{ t('oidc', 'Cancel') }}</button>
					</div>
				</div>
				<div v-if="allowUserSettings !== 'no'" class="consent-actions">
					<button :disabled="savingScopes || revoking !== null" @click="editScopes(consent)">{{ t('oidc', 'Edit permissions') }}</button>
					<button
						class="button secondary"
						:disabled="revoking === consent.clientId"
						@click="pendingRevocation = consent">
						<span v-if="revoking === consent.clientId" class="icon-loading-small"></span>
						<span v-else>{{ t('oidc', 'Revoke Access') }}</span>
					</button>
				</div>
			</div>
		</div>
		<div v-if="pendingRevocation" role="alert" class="revoke-confirmation">
			<p>{{ t('oidc', 'Are you sure you want to revoke access for "{clientName}"?', { clientName: pendingRevocation.clientName }) }}</p>
			<button @click="revokeAccess(pendingRevocation.clientId)">{{ t('oidc', 'Revoke Access') }}</button>
			<button @click="pendingRevocation = null">{{ t('oidc', 'Cancel') }}</button>
		</div>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

export default {
	name: 'AuthorizedApps',
	components: { NcNoteCard },
	props: {
		allowUserSettings: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			consents: [],
			loading: true,
			revoking: null,
			pendingRevocation: null,
			editingClient: null,
			selectedScopes: [],
			savingScopes: false,
			notification: null,
			revokedConsentIds: [],
		}
	},
	mounted() {
		this.loadConsents()
	},
	methods: {
		t,
		async loadConsents() {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/oidc/api/consents'), {
					cache: 'no-store',
					headers: {
						requesttoken: getRequestToken(),
					},
				})

				if (!response.ok) {
					console.error('[loadConsents] API Error:', response.status)
					this.showNotification('error', t('oidc', 'Failed to load authorized applications'))
					return
				}

				const data = await response.json()
				// A response started before revocation may still contain the old row.
				this.consents = data.filter(consent => !this.revokedConsentIds.includes(String(consent.id)))
			} catch (error) {
				console.error('[loadConsents] Exception:', error)
				this.showNotification('error', t('oidc', 'Failed to load authorized applications'))
			} finally {
				this.loading = false
			}
		},
		async revokeAccess(clientId) {
			if (this.revoking !== null) return
			this.pendingRevocation = null

			this.notification = null
			this.revoking = clientId
			try {
				const response = await fetch(generateUrl('/apps/oidc/api/consents/' + clientId), {
					method: 'DELETE',
					headers: {
						requesttoken: getRequestToken(),
					},
				})

				if (response.ok) {
					const revoked = this.consents.filter(consent => String(consent.clientId) === String(clientId))
					this.revokedConsentIds.push(...revoked.map(consent => String(consent.id)))
					this.consents = this.consents.filter(consent => String(consent.clientId) !== String(clientId))
					this.showNotification('success', t('oidc', 'Access revoked successfully'))
				} else {
					this.showNotification('error', t('oidc', 'Failed to revoke access'))
				}
			} catch (error) {
				console.error('Error revoking consent:', error)
				this.showNotification('error', t('oidc', 'Failed to revoke access'))
			} finally {
				this.revoking = null
			}
		},
		showNotification(type, text) {
			this.notification = { type, text }
		},
		getRequestedScopes(consent) {
			const requested = this.getScopes(consent.scopesRequested || consent.scopesGranted)
			const allowed = this.getScopes(consent.allowedScopes || '')
			return allowed.length ? requested.filter(scope => allowed.includes(scope)) : requested
		},
		editScopes(consent) {
			this.editingClient = consent.clientId
			this.selectedScopes = this.getScopes(consent.scopesGranted)
		},
		async saveScopes(consent) {
			if (this.savingScopes) return
			this.savingScopes = true
			try {
				const response = await fetch(generateUrl('/apps/oidc/api/consents/' + consent.clientId + '/scopes'), {
					method: 'PATCH',
					headers: { requesttoken: getRequestToken(), 'Content-Type': 'application/json' },
					body: JSON.stringify({ scopes: this.selectedScopes }),
				})
				if (!response.ok) throw new Error('Permission update failed')
				const result = await response.json()
				Object.assign(consent, { scopesGranted: result.scopesGranted, updatedAt: result.updatedAt, expiresAt: result.expiresAt })
				this.editingClient = null
				this.showNotification('success', t('oidc', 'Permissions updated'))
			} catch {
				this.showNotification('error', t('oidc', 'Failed to update permissions'))
			} finally {
				this.savingScopes = false
			}
		},
		getScopes(scopesString) {
			return String(scopesString || '').split(' ').filter(s => s.trim())
		},
		getAllowedScopes(consent) {
			// Return all scopes allowed by the client
			if (consent.allowedScopes) {
				return consent.allowedScopes.split(' ').filter(s => s.trim())
			}
			// Fallback to granted scopes if allowedScopes not available
			return this.getScopes(consent.scopesGranted)
		},
		isScopeGranted(consent, scope) {
			const grantedScopes = this.getScopes(consent.scopesGranted)
			return grantedScopes.includes(scope)
		},
		formatScopes(scopesString) {
			const scopeLabels = {
				openid: t('oidc', 'Basic authentication'),
				profile: t('oidc', 'Profile information'),
				email: t('oidc', 'Email address'),
				roles: t('oidc', 'Group memberships'),
				groups: t('oidc', 'Group memberships'),
			}

			return scopesString.split(' ')
				.map(scope => scopeLabels[scope] || scope)
				.join(', ')
		},
		formatDate(timestamp) {
			return new Date(timestamp * 1000).toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.authorized-apps {
	margin-top: 20px;
}

.description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
}

.loading {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 20px;
	color: var(--color-text-maxcontrast);
}

.empty-content {
	text-align: center;
	padding: 40px 20px;
	color: var(--color-text-maxcontrast);
}

.empty-content .icon-checkmark {
	font-size: 64px;
	margin-bottom: 15px;
	opacity: 0.3;
}

.consents-list {
	display: flex;
	flex-direction: column;
	gap: 15px;
}

.consent-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 15px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.consent-item:hover {
	background: var(--color-background-dark);
}

.consent-info {
	flex: 1;
}

.consent-info h4 {
	margin: 0 0 10px 0;
	font-weight: bold;
}

.consent-info .client-id {
	margin: 5px 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.consent-info .client-id code {
	background: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 3px;
	font-family: monospace;
	font-size: 11px;
}

.consent-info .scopes {
	margin: 5px 0;
	font-size: 14px;
}

.scope-badges {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
}

.scope-badge {
	display: inline-block;
	padding: 4px 10px;
	border-radius: 12px;
	font-size: 13px;
	transition: all 0.2s ease;
}

.scope-badge.active {
	background: var(--color-primary-element-light);
	border: 2px solid var(--color-primary-element);
	color: var(--color-main-text);
	font-weight: 500;
}

.scope-badge.inactive {
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	opacity: 0.7;
}

.consent-actions {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: stretch;
}

.consent-info .date {
	margin: 5px 0 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

button {
	padding: 8px 16px;
	white-space: nowrap;
	display: flex;
	align-items: center;
	gap: 5px;
}

button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}
</style>
