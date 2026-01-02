<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class Version0010Date20230411232100 extends SimpleMigrationStep {
    private LoggerInterface $logger;
    private IDBConnection $db;

    public function __construct(
                    IDBConnection $db,
                    LoggerInterface $logger
                    )
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Modify index on client id
        if ($schema->hasTable('oidc_loredirect_uris')) {
            $table = $schema->getTable('oidc_loredirect_uris');
            $table->dropIndex('oidc_loredir_uri_idx');
        }

        if (!$schema->hasTable('oidc_loredirect_uris')) {
            $table = $schema->createTable('oidc_loredirect_uris');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('redirect_uri', 'string', [
                'notnull' => true,
                'length' => 2000,
            ]);
            $table->setPrimaryKey(['id']);
        }

        return $schema;
    }

}
