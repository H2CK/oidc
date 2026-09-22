<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Per-group maximum scopes ("scope ceiling"). A user whose groups have rows
 * here can only be issued scopes from the union of those rows (plus the
 * default OpenID scopes); users in no configured group are unrestricted.
 */
class Version0034Date20260920100000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('oidc_group_scopes')) {
            $table = $schema->createTable('oidc_group_scopes');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('group_id', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('scopes', Types::STRING, [
                'notnull' => false,
                'length' => 512,
            ]);
            $table->setPrimaryKey(['id'], 'oidc_grp_scopes_pk');
            $table->addUniqueIndex(['group_id'], 'oidc_grp_scopes_gid_idx');
        }

        return $schema;
    }
}
