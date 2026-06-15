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

class Version0020Date20260615100100 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('oidc_authorization_codes')) {
            $table = $schema->createTable('oidc_authorization_codes');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('access_token_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('hashed_code', Types::STRING, [
                'notnull' => true,
                'length' => 128,
            ]);

            $table->addColumn('created', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);

            $table->addColumn('used_at', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['hashed_code'], 'oidc_auth_code_hash_idx');
            $table->addIndex(['access_token_id'], 'oidc_auth_code_token_idx');
            $table->addIndex(['created'], 'oidc_auth_code_created_idx');
            $table->addIndex(['used_at'], 'oidc_auth_code_used_idx');
        }

        return $schema;
    }
}
