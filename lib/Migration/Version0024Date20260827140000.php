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
 * Store an absolute expiry for newly issued access tokens. Existing rows keep
 * expires_at=0 and are evaluated with the historical refreshed + lifetime
 * fallback until they are renewed.
 */
class Version0024Date20260827140000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('oidc_access_tokens')) {
            return $schema;
        }

        $table = $schema->getTable('oidc_access_tokens');
        if (!$table->hasColumn('expires_at')) {
            $table->addColumn('expires_at', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);
        }

        return $schema;
    }
}
