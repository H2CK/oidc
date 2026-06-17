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

class Version0021Date20260617100100 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('oidc_access_tokens')) {
            $table = $schema->getTable('oidc_access_tokens');

            if (!$table->hasColumn('id_token_claims')) {
                $table->addColumn('id_token_claims', Types::TEXT, [
                    'notnull' => false,
                ]);
            }

            if (!$table->hasColumn('userinfo_claims')) {
                $table->addColumn('userinfo_claims', Types::TEXT, [
                    'notnull' => false,
                ]);
            }
        }

        return $schema;
    }
}
