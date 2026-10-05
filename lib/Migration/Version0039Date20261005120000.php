<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\OIDCIdentityProvider\Migration;

use OCA\OIDCIdentityProvider\Db\OperationLock;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0039Date20261005120000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('oidc_operation_locks')) {
            $table = $schema->createTable('oidc_operation_locks');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
        }
        if (!$schema->hasTable('oidc_id_token_hashes')) {
            $table = $schema->createTable('oidc_id_token_hashes');
            $table->addColumn('token_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('expires_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['token_hash']);
            $table->addIndex(['expires_at'], 'oidc_idt_exp_idx');
        }
        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        OperationLock::initialize($this->db);
    }
}
