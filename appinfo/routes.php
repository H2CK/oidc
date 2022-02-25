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
			'name' => 'Settings#addClient',
			'url' => '/clients',
			'verb' => 'POST',
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
			'name' => 'LoginRedirect#preflighted_cors',
			'url' => '/authorize',
			'verb' => 'OPTIONS',
			'requirements' => array('path' => '.+')
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
			'name' => 'OIDCApi#preflighted_cors',
			'url' => '/token',
			'verb' => 'OPTIONS',
			'requirements' => array('path' => '.+')
		],
		[
			'name' => 'UserInfo#getInfo',
			'url' => '/userinfo',
			'verb' => 'GET'
		],
		[
			'name' => 'UserInfo#preflighted_cors',
			'url' => '/userinfo',
			'verb' => 'OPTIONS',
			'requirements' => array('path' => '.+')
		],
		[
			'name' => 'Discovery#getInfo',
			'url' => '/openid-configuration',
			'verb' => 'GET'
		],
		[
			'name' => 'Discovery#preflighted_cors',
			'url' => '/openid-configuration',
			'verb' => 'OPTIONS',
			'requirements' => array('path' => '.+')
		],
		[
			'name' => 'Jwks#getKeyInfo',
			'url' => '/jwks',
			'verb' => 'GET'
		],
		[
			'name' => 'Jwks#preflighted_cors',
			'url' => '/jwks',
			'verb' => 'OPTIONS',
			'requirements' => array('path' => '.+')
		],
		[
			'name' => 'Logout#logout',
			'url' => '/logout',
			'verb' => 'GET'
		],
		[
			'name' => 'Logout#preflighted_cors',
			'url' => '/logout',
			'verb' => 'OPTIONS',
			'requirements' => array('path' => '.+')
		],
	],
];
