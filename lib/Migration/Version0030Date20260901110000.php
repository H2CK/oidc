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

/**
 * Version 2.2.0 upgrade:
 * - allow post_logout_redirect_uri entries to be bound to one RP while
 *   preserving existing rows as global fallback entries (client_id = NULL),
 * - invalidate persisted OIDC grant state so pre-2.2.0 clients must start a
 *   fresh authorization flow after the logout/session security changes.
 */
class Version0030Date20260901110000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db) {
    }

    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $authorizationCodesDeleted = 0;
        if ($schema->hasTable('oidc_authorization_codes')) {
            $authorizationCodesDeleted = $this->db->getQueryBuilder()
                ->delete('oidc_authorization_codes')
                ->executeStatement();
        }

        $accessTokensDeleted = 0;
        if ($schema->hasTable('oidc_access_tokens')) {
            $accessTokensDeleted = $this->db->getQueryBuilder()
                ->delete('oidc_access_tokens')
                ->executeStatement();
        }

        $output->info(sprintf(
            'OIDC 2.2.0 upgrade: invalidated %d authorization code(s) and %d access/refresh grant(s); relying parties must authorize again.',
            $authorizationCodesDeleted,
            $accessTokensDeleted
        ));
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('oidc_loredirect_uris')) {
            return $schema;
        }

        $table = $schema->getTable('oidc_loredirect_uris');
        if (!$table->hasColumn('client_id')) {
            $table->addColumn('client_id', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
        }
        if (!$table->hasIndex('oidc_loredir_client_idx')) {
            $table->addIndex(['client_id'], 'oidc_loredir_client_idx');
        }

        return $schema;
    }
}
