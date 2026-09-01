<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add OpenID Connect Back-Channel Logout client metadata and RP session IDs.
 */
class Version0029Date20260831100000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('oidc_clients')) {
            $table = $schema->getTable('oidc_clients');
            if (!$table->hasColumn('backchannel_logout_uri')) {
                $table->addColumn('backchannel_logout_uri', 'string', [
                    'notnull' => false,
                    'length' => 2000,
                ]);
            }

            if (!$table->hasColumn('backchannel_logout_sess_req')) {
                $table->addColumn('backchannel_logout_sess_req', 'boolean', [
                    'notnull' => false,
                    'default' => false,
                ]);
            }
        }

        if ($schema->hasTable('oidc_access_tokens')) {
            $table = $schema->getTable('oidc_access_tokens');
            if (!$table->hasColumn('sid')) {
                $table->addColumn('sid', 'string', [
                    'notnull' => false,
                    'length' => 64,
                ]);
            }
        }

        return $schema;
    }
}
