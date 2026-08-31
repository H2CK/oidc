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

class Version0022Date20260825100100 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('oidc_clients')) {
            $table = $schema->getTable('oidc_clients');

            if (!$table->hasColumn('tex_enabled')) {
                // Nullable on purpose: Nextcloud 32 rejects any BOOLEAN column
                // declared NOT NULL (a bool is an integer of length 1 that Oracle
                // cannot constrain that way), matching `dcr` in Version0011.
                $table->addColumn('tex_enabled', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => false,
                ]);
            }

            if (!$table->hasColumn('tex_allowed_scopes')) {
                $table->addColumn('tex_allowed_scopes', Types::TEXT, [
                    'notnull' => false,
                ]);
            }
        }

        if (!$schema->hasTable('oidc_tex_targets')) {
            $table = $schema->createTable('oidc_tex_targets');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('client_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('resource_url', Types::STRING, [
                'notnull' => false,
                'default' => null,
                'length' => 512,  // URLs can be long
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

            $table->setPrimaryKey(['id'], 'oidc_tex_targets_pk');
            $table->addUniqueIndex(['resource_url'], 'oidc_tex_targets_url_idx');
            $table->addIndex(['client_id'], 'oidc_tex_targets_client_idx');
            $table->addIndex(['created'], 'oidc_tex_targets_created_idx');
            $table->addIndex(['used_at'], 'oidc_tex_targets_used_idx');
        }

        return $schema;
    }
}
