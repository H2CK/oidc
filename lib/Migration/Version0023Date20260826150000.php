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
 * Allow the same RFC 8693 resource URI to be configured for different clients
 * while still preventing duplicate targets within one client.
 */
class Version0023Date20260826150000 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns an `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('oidc_tex_targets')) {
            return $schema;
        }

        $table = $schema->getTable('oidc_tex_targets');

        if ($table->hasIndex('oidc_tex_targets_url_idx')) {
            $table->dropIndex('oidc_tex_targets_url_idx');
        }

        if (!$table->hasIndex('oidc_tex_targets_client_url_idx')) {
            $table->addUniqueIndex(
                ['client_id', 'resource_url'],
                'oidc_tex_targets_client_url_idx'
            );
        }

        return $schema;
    }
}
