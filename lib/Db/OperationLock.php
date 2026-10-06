<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Acquire inside a transaction; locks are shared across all application nodes. */
class OperationLock {
    public const DCR = 1;
    public const AUTHORIZATION = 2;

    public static function consent(string $uid, int $clientId): int {
        return 3 + hexdec(substr(hash('sha256', $uid . '\0' . $clientId), 0, 2)) % 64;
    }

    public static function initialize(IDBConnection $db): void {
        for ($id = 1; $id <= 66; $id++) {
            $qb = $db->getQueryBuilder();
            $qb->select('id')->from('oidc_operation_locks')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $result = $qb->executeQuery();
            try {
                $exists = $result->fetchOne() !== false;
            } finally {
                $result->closeCursor();
            }
            if (!$exists) {
                $qb = $db->getQueryBuilder();
                $qb->insert('oidc_operation_locks')->values([
                    'id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT),
                ])->executeStatement();
            }
        }
    }

    public static function acquire(IDBConnection $db, int $id): void {
        $table = '*PREFIX*oidc_operation_locks';
        if ($db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
            if ($db->executeStatement('UPDATE ' . $table . ' SET id = id WHERE id = ?', [$id], [IQueryBuilder::PARAM_INT]) !== 1) {
                throw new \RuntimeException('OIDC operation lock is missing. Run the database migration.');
            }
        } else {
            $result = $db->executeQuery('SELECT id FROM ' . $table . ' WHERE id = ? FOR UPDATE', [$id], [IQueryBuilder::PARAM_INT]);
            try {
                if ($result->fetchOne() === false) {
                    throw new \RuntimeException('OIDC operation lock is missing. Run the database migration.');
                }
            } finally {
                $result->closeCursor();
            }
        }
    }
}
