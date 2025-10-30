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
            'url' => '/clients/redirect',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#deleteRedirectUri',
            'url' => '/clients/redirect/{id}',
            'verb' => 'DELETE'
        ],
        [
            'name' => 'Settings#addLogoutRedirectUri',
            'url' => '/logoutRedirect',
            'verb' => 'POST',
        ],
        [
            'name' => 'Settings#deleteLogoutRedirectUri',
            'url' => '/logoutRedirect/{id}',
            'verb' => 'DELETE'
        ],
        [
            'name' => 'Settings#addClient',
            'url' => '/clients',
            'verb' => 'POST',
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
            'name' => 'Settings#setAllowUserSettings',
            'url' => '/allowUserSettings',
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
            'name' => 'Settings#deleteClient',
            'url' => '/clients/{id}',
            'verb' => 'DELETE'
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
    ],
];
