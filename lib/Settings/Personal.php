<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Settings;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Personal implements ISettings {

        /** @var IAppConfig */
        private $appConfig;

        /**
         * Personal constructor.
         *
         * @param IAppConfig $appConfig
         */
        public function __construct(IAppConfig $appConfig,
        ) {
                $this->appConfig = $appConfig;
        }

        /**
         * @return TemplateResponse
         */
        public function getForm() {

                $parameters = [
                        'allow_user_settings' => $this->appConfig->getAppValueString(Application::APP_CONFIG_ALLOW_USER_SETTINGS, Application::DEFAULT_ALLOW_USER_SETTINGS),
                ];

                return new TemplateResponse('oidc', 'personal', $parameters);
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
