<?php
/**
 * @copyright Copyright (c) 2022 Thorsten Jagel <dev@jagel.net>
 *
 * @author Thorsten Jagel <dev@jagel.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
return [
    'routes' => [
        [
            'name' => 'Settings#addRedirectUri',
            'url' => '/api/v2/clients/redirect',
            'verb' => 'POST',
            'postfix' => 'v2',
        ],
        [
            'name' => 'Settings#addRedirectUri',
            'url' => '/clients/redirect',
            'verb' => 'POST',
            'postfix' => 'v1',
        ],
        [
            'name' => 'Settings#deleteRedirectUri',
            'url' => '/clients/redirect/{id}',
            'verb' => 'DELETE',
            'postfix' => 'v1',
        ],
        [
            'name' => 'Settings#deleteRedirectUri',
            'url' => '/api/v2/clients/redirect/{id}',
            'verb' => 'DELETE',
            'postfix' => 'v2',
        ],
        [
            'name' => 'Settings#addLogoutRedirectUri',
            'url' => '/logoutRedirect',
            'verb' => 'POST',
            'postfix' => 'v1',
        ],
        [
            'name' => 'Settings#addLogoutRedirectUri',
            'url' => '/api/v2/logoutRedirect',
            'verb' => 'POST',
            'postfix' => 'v2',
        ],
        [
            'name' => 'Settings#deleteLogoutRedirectUri',
            'url' => '/logoutRedirect/{id}',
            'verb' => 'DELETE',
            'postfix' => 'v1',
        ],
        [
            'name' => 'Settings#deleteLogoutRedirectUri',
            'url' => '/api/v2/logoutRedirect/{id}',
            'verb' => 'DELETE',
            'postfix' => 'v2',
        ],
        [
            'name' => 'Settings#addClient',
            'url' => '/clients',
            'verb' => 'POST',
            'postfix' => 'v1',
        ],
        [
            'name' => 'Settings#addClient',
            'url' => '/api/v2/clients',
            'verb' => 'POST',
            'postfix' => 'v2',
        ],
        [
            'name' => 'Settings#setTokenExpireTime',
            'url' => '/expire',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#setRefreshTokenExpireTime',
            'url' => '/refreshExpire',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#setOverwriteEmailVerified',
            'url' => '/overwriteEmailVerified',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#setDynamicClientRegistration',
            'url' => '/dynamicClientRegistration',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#setDefaultTokenType',
            'url' => '/defaultTokenType',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#setAllowUserSettings',
            'url' => '/allowUserSettings',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#setProvideRefreshTokenAlways',
            'url' => '/provideRefreshTokenAlways',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#restrictUserInformation',
            'url' => '/restrictUserInformation',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#restrictUserInformationPersonal',
            'url' => '/user/restrictUserInformation',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#regenerateKeys',
            'url' => '/genKeys',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#regenerateKeys',
            'url' => '/api/v2/genKeys',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#updateClient',
            'url' => '/clients/groups/{id}',
            'verb' => 'PATCH'
        ],
        [
            'name' => 'Settings#updateClientFlow',
            'url' => '/clients/flows/{id}',
            'verb' => 'PATCH'
        ],
        [
            'name' => 'Settings#updateTokenType',
            'url' => '/clients/token_type/{id}',
            'verb' => 'PATCH'
        ],
        [
            'name' => 'Settings#updateAllowedScopes',
            'url' => '/clients/allowed_scopes/{id}',
            'verb' => 'PATCH'
        ],
        [
            'name' => 'Settings#updateEmailRegex',
            'url' => '/clients/email_regex/{id}',
            'verb' => 'PATCH'
        ],
        [
            'name' => 'Settings#updateResourceUrl',
            'url' => '/clients/resource_url/{id}',
            'verb' => 'PATCH'
        ],
        [
            'name' => 'Settings#deleteClient',
            'url' => '/clients/{id}',
            'verb' => 'DELETE',
            'postfix' => 'v1',
        ],
        [
            'name' => 'Settings#deleteClient',
            'url' => '/api/v2/clients/{id}',
            'verb' => 'DELETE',
            'postfix' => 'v2',
        ],
        [
            'name' => 'LoginRedirector#authorize',
            'url' => '/authorize',
            'verb' => 'GET',
        ],
        [
            'name' => 'Cors#authorizeCorsResponse',
            'url' => '/authorize',
            'verb' => 'OPTIONS',
        ],
        [
            'name' => 'Page#index',
            'url' => '/redirect',
            'verb' => 'GET',
        ],
        [
            'name' => 'Consent#show',
            'url' => '/consent',
            'verb' => 'GET',
        ],
        [
            'name' => 'Consent#grant',
            'url' => '/consent/grant',
            'verb' => 'POST',
        ],
        [
            'name' => 'Consent#deny',
            'url' => '/consent/deny',
            'verb' => 'POST',
        ],
        [
            'name' => 'Consent#listUserConsents',
            'url' => '/api/consents',
            'verb' => 'GET',
        ],
        [
            'name' => 'Consent#revokeConsent',
            'url' => '/api/consents/{clientId}',
            'verb' => 'DELETE',
        ],
        [
            'name' => 'Consent#updateScopes',
            'url' => '/api/consents/{clientId}/scopes',
            'verb' => 'PATCH',
        ],
        [
            'name' => 'OIDCApi#getToken',
            'url' => '/token',
            'verb' => 'POST'
        ],
        [
            'name' => 'Cors#tokenCorsResponse',
            'url' => '/token',
            'verb' => 'OPTIONS',
        ],
        [
            'name' => 'UserInfo#getInfo',
            'url' => '/userinfo',
            'verb' => 'GET'
        ],
        [
            'name' => 'UserInfo#getInfoPost',
            'url' => '/userinfo',
            'verb' => 'POST'
        ],
        [
            'name' => 'Cors#userInfoCorsResponse',
            'url' => '/userinfo',
            'verb' => 'OPTIONS',
        ],
        [
            'name' => 'DynamicRegistration#registerClient',
            'url' => '/register',
            'verb' => 'POST'
        ],
        [
            'name' => 'Cors#registerCorsResponse',
            'url' => '/register',
            'verb' => 'OPTIONS',
        ],
        [
            'name' => 'DynamicRegistration#getClientConfiguration',
            'url' => '/register/{clientId}',
            'verb' => 'GET'
        ],
        [
            'name' => 'DynamicRegistration#updateClientConfiguration',
            'url' => '/register/{clientId}',
            'verb' => 'PUT'
        ],
        [
            'name' => 'DynamicRegistration#deleteClientConfiguration',
            'url' => '/register/{clientId}',
            'verb' => 'DELETE'
        ],
        [
            'name' => 'CustomClaim#getCustomClaim',
            'url' => '/customClaim',
            'verb' => 'GET',
            'postfix' => 'v1',
        ],
        [
            'name' => 'CustomClaim#getCustomClaim',
            'url' => '/api/v2/customClaim',
            'verb' => 'GET',
            'postfix' => 'v2',
        ],
        [
            'name' => 'CustomClaim#addCustomClaim',
            'url' => '/customClaim',
            'verb' => 'POST',
            'postfix' => 'v1',
        ],
        [
            'name' => 'CustomClaim#addCustomClaim',
            'url' => '/api/v2/customClaim',
            'verb' => 'POST',
            'postfix' => 'v2',
        ],
        [
            'name' => 'CustomClaim#updateCustomClaim',
            'url' => '/customClaim',
            'verb' => 'PATCH',
            'postfix' => 'v1',
        ],
        [
            'name' => 'CustomClaim#updateCustomClaim',
            'url' => '/api/v2/customClaim',
            'verb' => 'PATCH',
            'postfix' => 'v2',
        ],
        [
            'name' => 'CustomClaim#deleteCustomClaim',
            'url' => '/customClaim',
            'verb' => 'DELETE',
            'postfix' => 'v1',
        ],
        [
            'name' => 'CustomClaim#deleteCustomClaim',
            'url' => '/api/v2/customClaim',
            'verb' => 'DELETE',
            'postfix' => 'v2',
        ],
        [
            'name' => 'Cors#clientManagementCorsResponse',
            'url' => '/register/{clientId}',
            'verb' => 'OPTIONS',
        ],
        [
            'name' => 'Discovery#getInfo',
            'url' => '/openid-configuration',
            'verb' => 'GET'
        ],
        [
            'name' => 'Cors#discoveryCorsResponse',
            'url' => '/openid-configuration',
            'verb' => 'OPTIONS',
        ],
        [
            'name' => 'Jwks#getKeyInfo',
            'url' => '/jwks',
            'verb' => 'GET'
        ],
        [
            'name' => 'Cors#jwksCorsResponse',
            'url' => '/jwks',
            'verb' => 'OPTIONS',
        ],
        [
            'name' => 'Logout#logout',
            'url' => '/logout',
            'verb' => 'GET'
        ],
        [
            'name' => 'Cors#logoutCorsResponse',
            'url' => '/logout',
            'verb' => 'OPTIONS',
        ],
        [
            'name' => 'Introspection#introspectToken',
            'url' => '/introspect',
            'verb' => 'POST'
        ],
        [
            'name' => 'Cors#introspectionCorsResponse',
            'url' => '/introspect',
            'verb' => 'OPTIONS',
        ],
    ],
];
