<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0032Date20260902090000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('oidc_clients');
        if (!$table->hasColumn('frontchannel_logout_uri')) {
            $table->addColumn('frontchannel_logout_uri', Types::STRING, ['notnull' => false, 'length' => 2000]);
        }
        if (!$table->hasColumn('frontchannel_logout_sess_req')) {
            $table->addColumn('frontchannel_logout_sess_req', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
        }
        return $schema;
    }
}
