<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\OIDCIdentityProvider\Migration;

use OCA\OIDCIdentityProvider\Db\OperationLock;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class InitializeOperationLocks implements IRepairStep {
    public function __construct(private IDBConnection $db) {
    }

    public function getName(): string {
        return 'Initialize OIDC operation locks';
    }

    public function run(IOutput $output): void {
        OperationLock::initialize($this->db);
    }
}
