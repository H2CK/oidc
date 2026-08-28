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

/**
 * Persist RFC 8693 access-token lineage for precise UserInfo handling and
 * application-level revocation propagation. Legacy tokens keep NULL here.
 */
class Version0026Date20260828104500 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('oidc_access_tokens')) {
            $table = $schema->getTable('oidc_access_tokens');
            if (!$table->hasColumn('parent_token_id')) {
                $table->addColumn('parent_token_id', Types::INTEGER, [
                    'notnull' => false,
                    'unsigned' => true,
                ]);
                $table->addIndex(['parent_token_id'], 'oidc_at_parent_idx');
            }
        }

        return $schema;
    }
}
