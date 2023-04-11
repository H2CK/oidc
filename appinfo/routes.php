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
            'name' => 'LoginRedirector#authorizeCors',
            'url' => '/authorize',
            'verb' => 'OPTIONS',
        ],
        [
            'name' => 'Page#index',
            'url' => '/redirect',
            'verb' => 'GET',
        ],
        [
            'name' => 'OIDCApi#getToken',
            'url' => '/token',
            'verb' => 'POST'
        ],
		[
            'name' => 'OIDCApi#tokenCors',
            'url' => '/token',
            'verb' => 'OPTIONS',
        ],
        [
            'name' => 'UserInfo#getInfo',
            'url' => '/userinfo',
            'verb' => 'GET'
        ],
        [
            'name' => 'UserInfo#userInfoCors',
            'url' => '/userinfo',
            'verb' => 'OPTIONS',
        ],
        [
            'name' => 'Discovery#getInfo',
            'url' => '/openid-configuration',
            'verb' => 'GET'
        ],
        [
            'name' => 'Discovery#discoveryCors',
            'url' => '/openid-configuration',
            'verb' => 'OPTIONS',
        ],
        [
            'name' => 'Jwks#getKeyInfo',
            'url' => '/jwks',
            'verb' => 'GET'
        ],
        [
            'name' => 'Jwks#jwksCors',
            'url' => '/jwks',
            'verb' => 'OPTIONS',
        ],
        [
            'name' => 'Logout#logout',
            'url' => '/logout',
            'verb' => 'GET'
        ],
        [
            'name' => 'Logout#logoutCors',
            'url' => '/logout',
            'verb' => 'OPTIONS',
        ],
    ],
];
