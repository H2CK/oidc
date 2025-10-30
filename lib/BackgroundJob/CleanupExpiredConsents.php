<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\BackgroundJob;

use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;

class CleanupExpiredConsents extends TimedJob {

    /** @var UserConsentMapper */
    private $userConsentMapper;
    /** @var IConfig */
    private $settings;

    /**
     * @param ITimeFactory $time
     * @param UserConsentMapper $userConsentMapper
     * @param IConfig $settings
     */
    public function __construct(ITimeFactory $time,
                                UserConsentMapper $userConsentMapper,
                                IConfig $settings) {
        parent::__construct($time);
        $this->userConsentMapper = $userConsentMapper;
        $this->settings = $settings;

        // Run four times a day
        $this->setInterval(6 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        // Don't run CleanUpJob when backgroundjobs_mode is ajax or webcron
        // if ($this->settings->getAppValue('core', 'backgroundjobs_mode') !== 'cron') return;
        $this->userConsentMapper->cleanUp();
    }
}
