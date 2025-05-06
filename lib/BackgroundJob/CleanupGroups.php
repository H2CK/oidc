<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\BackgroundJob;

use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;

class CleanupGroups extends TimedJob {

    /** @var GroupMapper */
    private $groupMapper;
    /** @var IConfig */
    private $settings;

    /**
     * @param ITimeFactory $time
     * @param GroupMapper $groupMapper
     */
    public function __construct(ITimeFactory $time,
                                GroupMapper $groupMapper,
                                IConfig $settings) {
        parent::__construct($time);
        $this->groupMapper = $groupMapper;
        $this->settings = $settings;

        // Run once a day
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        // Don't run CleanUpJob when backgroundjobs_mode is ajax or webcron
        // if ($this->settings->getAppValue('core', 'backgroundjobs_mode') !== 'cron') return;
        $this->groupMapper->cleanUp();
    }
}
