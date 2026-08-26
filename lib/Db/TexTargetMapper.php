<?php

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TexTargets>
 */
class TexTargetMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'oidc_tex_targets', TexTargets::class);
    }

    public function findByResourceUrl(string $resourceUrl): ?TexTargets {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('resource_url', $qb->createNamedParameter($resourceUrl)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * @return TexTargets[]
     */
    public function getByClientId(int $clientId): array {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created', 'ASC');

        return $this->findEntities($qb);
    }

    public function markUsed(TexTargets $target, int $usedAt): bool {
        $qb = $this->db->getQueryBuilder();
        $updated = $qb
            ->update($this->getTableName())
            ->set('used_at', $qb->createNamedParameter($usedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($target->getId(), IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('used_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        if ($updated === 1) {
            $target->setUsedAt($usedAt);
            return true;
        }

        return false;
    }

    public function deleteByClientId(int $clientId): void {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->getTableName())
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    public function cleanUp(int $createdBefore): void {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->getTableName())
            ->where($qb->expr()->lt('created', $qb->createNamedParameter($createdBefore, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
