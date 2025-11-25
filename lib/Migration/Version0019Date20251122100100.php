<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add oidc_custom_claims table.
 *
 * This table stores custom claims definitions for clients.
 */
class Version0019Date20251122100100 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('oidc_custom_claims')) {
            $table = $schema->createTable('oidc_custom_claims');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('client_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('scope', Types::STRING, [
                'notnull' => true,
                'length' => 128,
            ]);

			$table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 128,
            ]);

			$table->addColumn('function', Types::STRING, [
                'notnull' => true,
                'length' => 128,
            ]);

			$table->addColumn('parameter', Types::STRING, [
                'notnull' => false,
                'length' => 128,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['client_id'], 'oidc_cc_cid');
        }

        return $schema;
    }
}
