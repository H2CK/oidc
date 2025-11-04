<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add resource_url column to oidc_clients table.
 *
 * This field stores the OAuth 2.0 Protected Resource URL (RFC 9728)
 * that this client represents. When a token is issued with a resource
 * parameter (RFC 8707), the introspection endpoint can match the URL
 * to the client that should be allowed to introspect it.
 *
 * This enables proper audience-restricted token introspection where:
 * - Client A requests token with resource=https://api.example.com
 * - Client B (resource server at https://api.example.com) can introspect
 *   the token even though Client A owns it
 * - Client C cannot introspect the token (not owner, not audience)
 */
class Version0018Date20251104100100 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('oidc_clients');

        // Add resource_url column if it doesn't exist
        if (!$table->hasColumn('resource_url')) {
            $table->addColumn('resource_url', Types::STRING, [
                'notnull' => false,
                'default' => null,
                'length' => 512,  // URLs can be long
            ]);
            $output->info('Added resource_url column to oidc_clients table');
        }

        return $schema;
    }
}
