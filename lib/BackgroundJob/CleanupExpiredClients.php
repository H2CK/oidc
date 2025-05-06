<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\BackgroundJob;

use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;

class CleanupExpiredClients extends TimedJob {

    /** @var ClientMapper */
    private $clientMapper;
    /** @var IConfig */
    private $settings;

    /**
     * @param ITimeFactory $time
     * @param ClientMapper $clientMapper
     */
    public function __construct(ITimeFactory $time,
                                ClientMapper $clientMapper,
                                IConfig $settings) {
        parent::__construct($time);
        $this->clientMapper = $clientMapper;
        $this->settings = $settings;

        // Run each hour
        $this->setInterval(1 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        // Don't run CleanUpJob when backgroundjobs_mode is ajax or webcron
        // if ($this->settings->getAppValue('core', 'backgroundjobs_mode') !== 'cron') return;
        $this->clientMapper->cleanUp();
    }
}
