<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
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
use OCP\DB\Types;
use Doctrine\DBAL\Types\Type;

class Version0012Date20250402100100 extends SimpleMigrationStep {
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

        $table = $schema->getTable('oidc_clients');
        if($table->hasColumn('jwt')) {
            $table->dropColumn('jwt');
        }
        if($table->hasColumn('jwt_access_token')) {
            $table->dropColumn('jwt_access_token');
        }

        if(!$table->hasColumn('token_type')) {
            $table->addColumn('token_type', Types::STRING, [
                'notnull' => false,
                'default' => 'opaque',
                'length' => 16,
            ]);
        }

        $table = $schema->getTable('oidc_access_tokens');
        if (!$table->hasColumn('resource')) {
            $table->addColumn('resource', 'string', [
                'notnull' => false,
                'length' => 2000,
            ]);
        }

        $column = $table->getColumn('access_token');
        $column->setType(Type::getType('text'))
            ->setLength(65535)
            ->setNotnull(false);

        return $schema;
    }

}
