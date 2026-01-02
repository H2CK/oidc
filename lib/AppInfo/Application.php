<?php

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
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

class Application extends App implements IBootstrap
{
    public const APP_ID = 'oidc';

    public const DEFAULT_SCOPE = 'openid profile email roles';
    public const DEFAULT_EXPIRE_TIME = '900';
    public const DEFAULT_REFRESH_EXPIRE_TIME = '900';
    public const DEFAULT_CLIENT_EXPIRE_TIME = '3600';
    public const DEFAULT_RESOURCE_IDENTIFIER = 'https://rs.local/';
    public const DEFAULT_ALLOW_USER_SETTINGS = 'no';
    public const DEFAULT_RESTRICT_USER_INFORMATION = 'no';
    public const DEFAULT_TOKEN_TYPE = 'opaque';
    public const DEFAULT_PROVIDE_REFRESH_TOKEN_ALWAYS = 'false';
    public const DEFAULT_ALLOW_SUBDOMAIN_WILDCARDS = 'false';

    public const GROUP_CLAIM_TYPE_GID = 'gid';
    public const GROUP_CLAIM_TYPE_DISPLAYNAME = 'displayname';

    public const APP_CONFIG_DEFAULT_EXPIRE_TIME = 'expire_time';
    public const APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME = 'refresh_expire_time';
    public const APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME = 'client_expire_time';
    public const APP_CONFIG_DEFAULT_RESOURCE_IDENTIFIER = 'default_resource_identifier';
    public const APP_CONFIG_OVERWRITE_EMAIL_VERIFIED = 'overwrite_email_verified';
    public const APP_CONFIG_DYNAMIC_CLIENT_REGISTRATION = 'dynamic_client_registration';
    public const APP_CONFIG_ALLOW_USER_SETTINGS = 'allow_user_settings';
    public const APP_CONFIG_RESTRICT_USER_INFORMATION = 'restrict_user_information';
    public const APP_CONFIG_GROUP_CLAIM_TYPE = 'group_claim_type';
    public const APP_CONFIG_ROLES_CLAIM_TYPE = 'role_claim_type';
    public const APP_CONFIG_DEFAULT_TOKEN_TYPE = 'default_token_type';
    public const APP_CONFIG_PROVIDE_REFRESH_TOKEN_ALWAYS = 'provide_refresh_token_always';
    public const APP_CONFIG_ALLOW_SUBDOMAIN_WILDCARDS = 'allow_subdomain_wildcards';

    private $backend;

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void
    {
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

    public function boot(IBootContext $context): void
    {
        // Currently not needed
    }
}
