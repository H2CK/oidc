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
 * Normalize the Token Exchange target unique-index name to a portable length.
 *
 * Version0023 originally used oidc_tex_targets_client_url_idx (31 characters),
 * which exceeds Nextcloud's portable identifier limit. Fresh installations use
 * the shortened name directly in Version0023. This migration repairs databases
 * where the older migration was already applied.
 */
class Version0028Date20260828130000 extends SimpleMigrationStep {
    private const OLD_INDEX = 'oidc_tex_targets_client_url_idx';
    private const NEW_INDEX = 'oidc_tex_tgt_client_url_idx';

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('oidc_tex_targets')) {
            return $schema;
        }

        $table = $schema->getTable('oidc_tex_targets');

        if ($table->hasIndex(self::OLD_INDEX)) {
            $table->dropIndex(self::OLD_INDEX);
        }

        if (!$table->hasIndex(self::NEW_INDEX)) {
            $table->addUniqueIndex(
                ['client_id', 'resource_url'],
                self::NEW_INDEX
            );
        }

        return $schema;
    }
}
