<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<RecentSession> */
class RecentSessionMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'oidc_recent_sessions', RecentSession::class);
    }

    public function remember(string $userId, string $clientIdentifier, string $sid, int $loggedOutAt): void {
        if ($userId === '' || $clientIdentifier === '' || $sid === '') {
            return;
        }

        // Refresh an existing identical correlation rather than accumulating
        // duplicates when a logout event is observed more than once.
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('client_identifier', $qb->createNamedParameter($clientIdentifier)))
            ->andWhere($qb->expr()->eq('sid', $qb->createNamedParameter($sid)));
        $qb->executeStatement();

        $entry = new RecentSession();
        $entry->setUserId($userId);
        $entry->setClientIdentifier($clientIdentifier);
        $entry->setSid($sid);
        $entry->setLoggedOutAt($loggedOutAt);
        $this->insert($entry);
    }

    public function isRecent(string $userId, string $clientIdentifier, ?string $sid, int $notBefore): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('client_identifier', $qb->createNamedParameter($clientIdentifier)))
            ->andWhere($qb->expr()->gte('logged_out_at', $qb->createNamedParameter($notBefore, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        if ($sid !== null && $sid !== '') {
            $qb->andWhere($qb->expr()->eq('sid', $qb->createNamedParameter($sid)));
        }

        $result = $qb->executeQuery();
        try {
            return $result->fetchOne() !== false;
        } finally {
            $result->closeCursor();
        }
    }

    public function cleanUp(int $olderThan): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->tableName)
            ->where($qb->expr()->lt('logged_out_at', $qb->createNamedParameter($olderThan, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
