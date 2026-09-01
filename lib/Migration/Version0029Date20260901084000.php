<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Invalidate OIDC grant/session state created before strict sid correlation.
 *
 * Existing access-token rows also carry the rotating refresh-token state used
 * by this provider. Keeping those rows across the Back-Channel Logout upgrade
 * would allow a pre-upgrade refresh grant to mint a new ID Token without a sid
 * that can be correlated to the current OP browser session. Authorization codes
 * are removed first to avoid leaving a one-time grant referencing invalidated
 * access-token state. Token Exchange descendants are removed by the existing
 * parent_token_id ON DELETE CASCADE relationship.
 */
class Version0029Date20260901084000 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db,
    ) {
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
            'Invalidated pre-upgrade OIDC state: %d authorization code(s), %d access/refresh/session grant(s).',
            $authorizationCodesDeleted,
            $accessTokensDeleted
        ));
    }
}
