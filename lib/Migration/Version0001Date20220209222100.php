<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0001Date20220209222100 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('oidc_clients')) {
            $schema->dropTable('oidc_clients');
        }

        if (!$schema->hasTable('oidc_clients')) {
            $table = $schema->createTable('oidc_clients');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('name', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('redirect_uri', 'string', [
                'notnull' => true,
                'length' => 2000,
            ]);
            $table->addColumn('client_identifier', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('secret', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('signing_alg', 'string', [
                'notnull' => true,
                'length' => 16,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['client_identifier'], 'oidc_client_id_idx');
        }

        if (!$schema->hasTable('oidc_access_tokens')) {
            $table = $schema->createTable('oidc_access_tokens');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('client_id', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 256,
            ]);
            $table->addColumn('scope', 'string', [
                'notnull' => true,
                'length' => 128,
            ]);
            $table->addColumn('hashed_code', 'string', [
                'notnull' => true,
                'length' => 128,
            ]);
            $table->addColumn('access_token', 'string', [
                'notnull' => true,
                'length' => 786,
            ]);
            $table->addColumn('created', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);
            $table->addColumn('refreshed', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['hashed_code'], 'oidc_access_hash_idx');
            $table->addIndex(['client_id'], 'oidc_access_client_id_idx');
            // $table->addIndex(['access_token'], 'oidc_access_access_token_idx'); Too big for MySQL/MariaDB
        }

        return $schema;
    }
}
