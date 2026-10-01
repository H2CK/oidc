<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0035Date20260930090000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('oidc_auth_transactions')) {
            return $schema;
        }

        $table = $schema->createTable('oidc_auth_transactions');
        // Only a hash of the browser's random identifier is stored.
        $table->addColumn('id', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('request_payload', Types::TEXT, ['notnull' => true]);
        $table->addColumn('reason', Types::STRING, ['length' => 32, 'notnull' => true]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('consumed_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['expires_at'], 'oidc_auth_tx_expiry');
        return $schema;
    }
}
