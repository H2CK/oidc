<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 OIDC Identity Provider contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0038Date20261005090000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $columns = [
            'oidc_clients' => [
                'grant_types' => [Types::STRING, ['length' => 512]],
                'response_types' => [Types::STRING, ['length' => 512]],
            ],
            'oidc_refresh_tokens' => ['scope' => [Types::STRING, ['length' => 512]]],
            'oidc_access_tokens' => ['auth_time' => [Types::BIGINT, []]],
            'oidc_device_codes' => ['auth_time' => [Types::BIGINT, []]],
        ];
        foreach ($columns as $name => $definitions) {
            if (!$schema->hasTable($name)) {
                continue;
            }
            $table = $schema->getTable($name);
            foreach ($definitions as $column => [$type, $settings]) {
                if (!$table->hasColumn($column)) {
                    $table->addColumn($column, $type, ['notnull' => false] + $settings);
                }
            }
        }
        // Unknown historical authentication times cannot be reconstructed.
        // Legacy refresh scopes are captured from their grant at first rotation.
        return $schema;
    }
}
