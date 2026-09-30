<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\BackgroundJob;

use OCA\OIDCIdentityProvider\Service\AuthorizationTransactionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class CleanupAuthorizationTransactions extends TimedJob {
    public function __construct(ITimeFactory $time, private AuthorizationTransactionService $transactions) {
        parent::__construct($time);
        $this->setInterval(3600);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $this->transactions->cleanup();
    }
}
