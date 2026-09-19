<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<GroupScope>
 */
class GroupScopeMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'oidc_group_scopes', GroupScope::class);
    }

    /**
     * @param string[] $groupIds
     * @return GroupScope[]
     */
    public function findByGroupIds(array $groupIds): array {
        if ($groupIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->in('group_id', $qb->createNamedParameter(array_values($groupIds), IQueryBuilder::PARAM_STR_ARRAY)));

        return $this->findEntities($qb);
    }

    /**
     * @return GroupScope[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->orderBy('group_id');

        return $this->findEntities($qb);
    }

    /**
     * Create or replace the scope ceiling of a group.
     */
    public function upsert(string $groupId, string $scopes): GroupScope {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));

        try {
            $entity = $this->findEntity($qb);
            $entity->setScopes($scopes);
            return $this->update($entity);
        } catch (DoesNotExistException) {
            $entity = new GroupScope();
            $entity->setGroupId($groupId);
            $entity->setScopes($scopes);
            return $this->insert($entity);
        }
    }

    public function deleteByGroupId(string $groupId): void {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->tableName)
            ->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));
        $qb->executeStatement();
    }
}
