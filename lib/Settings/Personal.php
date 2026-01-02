<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Settings;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCP\AppFramework\Services\IInitialState;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\IUserSession;

class Personal implements ISettings {
    /** @var IInitialState */
    private $initialState;

    /** @var IAppConfig */
    private $appConfig;

    /** @var IConfig */
    private $config;

    /** @var IUserSession */
    private $userSession;

    /**
     * Personal constructor.
     *
     * @param IAppConfig $appConfig
     */
    public function __construct(
        IInitialState $initialState,
        IAppConfig $appConfig,
        IConfig $config,
        IUserSession $userSession
    ) {
        $this->initialState = $initialState;
        $this->appConfig = $appConfig;
        $this->config = $config;
        $this->userSession = $userSession;
    }

    /**
     * @return TemplateResponse
     */
    public function getForm() {
        $currentUser = $this->userSession->getUser();
        $userId = $currentUser->getUID();

        $parameters = [];

        $this->initialState->provideInitialState(
            'allowUserSettings', $this->appConfig->getAppValueString(Application::APP_CONFIG_ALLOW_USER_SETTINGS, Application::DEFAULT_ALLOW_USER_SETTINGS));
        $this->initialState->provideInitialState(
            'restrictUserInformation', $this->config->getUserValue($userId, Application::APP_ID, Application::APP_CONFIG_RESTRICT_USER_INFORMATION, Application::DEFAULT_RESTRICT_USER_INFORMATION));

        return new TemplateResponse(Application::APP_ID, 'personal', $parameters);
    }

    /**
     * The section ID, e.g. 'sharing'
     *
     * @return string
     */
    public function getSection() {
        return 'oidc_provider_personal';
    }

    /**
     * Whether the form should be rather on the top or bottom of
     * the admin section. The forms are arranged in ascending order of the
     * priority values. It is required to return a value between 0 and 100.
     *
     * @return int
     */
    public function getPriority() {
        return 0;
    }

}
