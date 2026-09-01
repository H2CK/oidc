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

/**
 * Add a short-lived RP session history used solely to validate the
 * "current or recent session" semantics of RP-Initiated Logout hints.
 */
class Version0031Date20260901142000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('oidc_recent_sessions')) {
            return $schema;
        }

        $table = $schema->createTable('oidc_recent_sessions');
        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('client_identifier', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('sid', Types::STRING, [
            'notnull' => true,
            'length' => 128,
        ]);
        $table->addColumn('logged_out_at', Types::BIGINT, [
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id', 'client_identifier', 'sid'], 'oidc_recent_session_lookup');
        $table->addIndex(['logged_out_at'], 'oidc_recent_session_time');

        return $schema;
    }
}
