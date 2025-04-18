<?php

/**
 * @copyright Copyright (c) 2022-2025 Thorsten Jagel <dev@jagel.net>
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
namespace OCA\OIDCIdentityProvider\AppInfo;

use OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent;
use OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent;
use OCA\OIDCIdentityProvider\Http\WellKnown\WebFingerHandler;
use OCA\OIDCIdentityProvider\Http\WellKnown\OIDCDiscoveryHandler;
use OCA\OIDCIdentityProvider\BasicAuthBackend;
use OCA\OIDCIdentityProvider\Listener\TokenGenerationRequestListener;
use OCA\OIDCIdentityProvider\Listener\TokenValidationRequestListener;
use OCP\AppFramework\App;
use OC_User;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'oidc';

    public const DEFAULT_SCOPE = 'openid profile email roles';
    public const DEFAULT_EXPIRE_TIME = '900';
    public const DEFAULT_REFRESH_EXPIRE_TIME = '900';
    public const DEFAULT_CLIENT_EXPIRE_TIME = '3600';
    public const DEFAULT_RESOURCE_IDENTIFIER = 'https://rs.local/';

    private $backend;

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Register the composer autoloader for packages shipped by this app
        require_once __DIR__ . '/../../vendor/autoload.php';
        // Register WebFingerHandler
        $context->registerWellKnownHandler(WebFingerHandler::class);
        // Register OIDCDiscoveryHandler
        $context->registerWellKnownHandler(OIDCDiscoveryHandler::class);

        $context->registerEventListener(TokenValidationRequestEvent::class, TokenValidationRequestListener::class);
        $context->registerEventListener(TokenGenerationRequestEvent::class, TokenGenerationRequestListener::class);

        $this->backend = $this->getContainer()->get(BasicAuthBackend::class);
        OC_User::useBackend($this->backend);
    }

    public function boot(IBootContext $context): void {
        // Currently not needed
    }

}
