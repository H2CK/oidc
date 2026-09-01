<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\BackgroundJob;

use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

class BackChannelLogoutRetryJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private BackChannelLogoutService $backChannelLogoutService,
    ) {
        parent::__construct($time);
    }

    /** @param mixed $argument */
    protected function run($argument): void {
        if (!is_array($argument)) {
            return;
        }

        $this->backChannelLogoutService->retry($argument);
    }
}
