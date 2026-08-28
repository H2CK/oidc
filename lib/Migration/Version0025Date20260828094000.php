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
 * Add an explicit administrative allow-list that binds each Token Exchange
 * client to the clients from which it may accept subject access tokens.
 * Existing TEX clients intentionally get no implicit entries and therefore
 * fail closed until an administrator configures the policy.
 */
class Version0025Date20260828094000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('oidc_tex_subjects')) {
            $table = $schema->createTable('oidc_tex_subjects');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('client_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('subject_client_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->setPrimaryKey(['id'], 'oidc_tex_subjects_pk');
            $table->addUniqueIndex(['client_id', 'subject_client_id'], 'oidc_tex_subjects_pair_idx');
            $table->addIndex(['client_id'], 'oidc_tex_subjects_client_idx');
            $table->addIndex(['subject_client_id'], 'oidc_tex_subjects_subject_idx');
        }

        return $schema;
    }
}
