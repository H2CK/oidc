<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0036Date20260930150000 extends SimpleMigrationStep {
    private const REFRESH_ACCESS_FOREIGN_KEY = 'oidc_refresh_access_fk';

    public function __construct(private IDBConnection $db) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('oidc_authorization_codes')) {
            $authorizationCodes = $schema->getTable('oidc_authorization_codes');
            if (!$authorizationCodes->hasColumn('redirect_uri')) {
                // Existing in-flight codes fail closed after the upgrade. Newly
                // issued codes always contain the concrete redirect URI.
                $authorizationCodes->addColumn('redirect_uri', Types::STRING, [
                    'notnull' => false,
                    'length' => 2000,
                ]);
            }
        }

        if ($schema->hasTable('oidc_access_tokens')) {
            $accessTokens = $schema->getTable('oidc_access_tokens');
            if (!$accessTokens->hasColumn('legacy_refresh_token')) {
                $accessTokens->addColumn('legacy_refresh_token', Types::BOOLEAN, [
                    // Keep BOOLEAN nullable for Oracle compatibility, matching
                    // the app's existing boolean columns.
                    'notnull' => false,
                    'default' => false,
                ]);
            }
        }

        if (!$schema->hasTable('oidc_refresh_tokens')) {
            $refreshTokens = $schema->createTable('oidc_refresh_tokens');
            $refreshTokens->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $refreshTokens->addColumn('access_token_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $refreshTokens->addColumn('hashed_token', Types::STRING, [
                'notnull' => true,
                'length' => 128,
            ]);
            $refreshTokens->addColumn('created', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $refreshTokens->addColumn('used_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
                'default' => 0,
            ]);
            $refreshTokens->setPrimaryKey(['id'], 'oidc_refresh_token_pk');
            $refreshTokens->addUniqueIndex(['hashed_token'], 'oidc_refresh_hash_idx');
            $refreshTokens->addIndex(['access_token_id'], 'oidc_refresh_access_idx');
            $refreshTokens->addIndex(['used_at'], 'oidc_refresh_used_idx');
        }

        $refreshTokens = $schema->getTable('oidc_refresh_tokens');
        if ($schema->hasTable('oidc_access_tokens')
            && !$refreshTokens->hasForeignKey(self::REFRESH_ACCESS_FOREIGN_KEY)) {
            $refreshTokens->addForeignKeyConstraint(
                $schema->getTable('oidc_access_tokens'),
                ['access_token_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                self::REFRESH_ACCESS_FOREIGN_KEY
            );
        }

        if ($schema->hasTable('oidc_clients')) {
            $clients = $schema->getTable('oidc_clients');
            if (!$clients->hasColumn('application_type')) {
                $clients->addColumn('application_type', Types::STRING, [
                    'notnull' => false,
                    'length' => 16,
                ]);
            }
            if (!$clients->hasColumn('token_endpoint_auth_method')) {
                $clients->addColumn('token_endpoint_auth_method', Types::STRING, [
                    'notnull' => false,
                    'length' => 32,
                ]);
            }
        }

        return $schema;
    }

    /**
     * Preserve real refresh tokens issued before the dedicated table existed,
     * while keeping unconsumed authorization codes out of that compatibility
     * path. Future rows use the column's fail-closed false default.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('oidc_access_tokens')
            || !$schema->getTable('oidc_access_tokens')->hasColumn('legacy_refresh_token')) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('oidc_access_tokens')
            ->set('legacy_refresh_token', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
            ->executeStatement();

        if (!$schema->hasTable('oidc_authorization_codes')) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('access_token_id')
            ->from('oidc_authorization_codes')
            ->where($qb->expr()->eq('used_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        try {
            while (($accessTokenId = $result->fetchOne()) !== false) {
                $update = $this->db->getQueryBuilder();
                $update->update('oidc_access_tokens')
                    ->set('legacy_refresh_token', $update->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
                    ->where($update->expr()->eq(
                        'id',
                        $update->createNamedParameter((int)$accessTokenId, IQueryBuilder::PARAM_INT)
                    ))
                    ->executeStatement();
            }
        } finally {
            $result->closeCursor();
        }
    }
}
