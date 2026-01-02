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
use OCP\IDBConnection;
use OCP\DB\Types;

class Version0014Date20251019100100 extends SimpleMigrationStep {
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

        $table = $schema->getTable('oidc_access_tokens');

        if(!$table->hasColumn('code_challenge')) {
            $table->addColumn('code_challenge', Types::STRING, [
                'notnull' => false,
                'default' => null,
                'length' => 128,
            ]);
        }

        if(!$table->hasColumn('code_challenge_method')) {
            $table->addColumn('code_challenge_method', Types::STRING, [
                'notnull' => false,
                'default' => null,
                'length' => 16,
            ]);
        }

        return $schema;
    }

}
