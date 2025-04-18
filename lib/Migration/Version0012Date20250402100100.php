<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2024 Thorsten Jagel <dev@jagel.net>
 *
 * @author Thorsten Jagel <dev@jagel.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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
