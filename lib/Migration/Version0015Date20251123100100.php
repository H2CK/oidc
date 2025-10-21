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

class Version0015Date20251123100100 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('oidc_user_consents')) {
            $table = $schema->createTable('oidc_user_consents');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 256,
            ]);
            $table->addColumn('client_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('scopes_granted', Types::STRING, [
                'notnull' => true,
                'length' => 512,
            ]);
            $table->addColumn('created_at', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('updated_at', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('expires_at', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
                'default' => null,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'client_id'], 'oidc_consent_user_client_idx');
            $table->addIndex(['client_id'], 'oidc_consent_client_id_idx');
        }

        return $schema;
    }
}
