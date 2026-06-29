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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0022Date20260629100100 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('oidc_auth_codes')) {
            $table = $schema->createTable('oidc_auth_codes');

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
            $table->addUniqueIndex(['hashed_code'], 'oidc_a_code_hash_idx');
            $table->addIndex(['access_token_id'], 'oidc_a_code_token_idx');
            $table->addIndex(['created'], 'oidc_a_code_created_idx');
            $table->addIndex(['used_at'], 'oidc_a_code_used_idx');
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $schema = $schemaClosure();

        if (!$schema->hasTable('oidc_authorization_codes') ||
            !$schema->hasTable('oidc_auth_codes')) {
            return;
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('oidc_authorization_codes');

        $result = $qb->executeQuery();
        $migratedCount = 0;

        try {
            while ($row = $result->fetchAssociative()) {
                $insert = $this->db->getQueryBuilder();

                $insert->insert('oidc_auth_codes')
                    ->values([
                        'id' => $insert->createNamedParameter($row['id']),
                        'access_token_id' => $insert->createNamedParameter($row['access_token_id']),
                        'hashed_code' => $insert->createNamedParameter($row['hashed_code']),
                        'created' => $insert->createNamedParameter($row['created']),
                        'used_at' => $insert->createNamedParameter($row['used_at']),
                    ]);

                $insert->executeStatement();
                $migratedCount++;
            }
        } finally {
            $result->closeCursor();
        }

        $output->info("Migrated {$migratedCount} authorization code(s) from oidc_authorization_codes to oidc_auth_codes");

        $this->db->executeStatement('DROP TABLE IF EXISTS `*PREFIX*oidc_authorization_codes`');
    }
}
