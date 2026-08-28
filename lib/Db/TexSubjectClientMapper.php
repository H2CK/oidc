<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TexSubjectClient>
 */
class TexSubjectClientMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'oidc_tex_subjects', TexSubjectClient::class);
    }

    /**
     * @return TexSubjectClient[]
     */
    public function getByClientId(int $clientId): array {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
            ->orderBy('subject_client_id', 'ASC');

        return $this->findEntities($qb);
    }

    public function isAllowed(int $clientId, int $subjectClientId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('subject_client_id', $qb->createNamedParameter($subjectClientId, IQueryBuilder::PARAM_INT)));

        try {
            $this->findEntity($qb);
            return true;
        } catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
            return false;
        }
    }

    public function deleteByClientId(int $clientId): void {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->getTableName())
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    public function deleteBySubjectClientId(int $subjectClientId): void {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->getTableName())
            ->where($qb->expr()->eq('subject_client_id', $qb->createNamedParameter($subjectClientId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    public function deleteAllForClientId(int $clientId): void {
        $this->deleteByClientId($clientId);
        $this->deleteBySubjectClientId($clientId);
    }
}
