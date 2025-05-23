<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCP\AppFramework\Db\IMapperException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;

/**
 * @template-extends QBMapper<Group>
 */
class GroupMapper extends QBMapper {

    /** @var IGroupManager */
    private $groupManager;

    /**
     * @param IDBConnection $db
     * @param IGroupManager $groupManager
     */
    public function __construct(IDBConnection $db, IGroupManager $groupManager) {
        parent::__construct($db, 'oidc_group_map');
        $this->groupManager = $groupManager;
    }

    /**
     * @param int $id
     * @return Group
     * @throws ClientNotFoundException
     */
    public function getByIdentifier(string $id): Group {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        try {
            $client = $this->findEntity($qb);
        } catch (IMapperException $e) {
            throw new ClientNotFoundException('could not find group mapping '.$id, 0, $e);
        }
        return $client;
    }

    /**
     * @param int $clientId
     * @return Groups[]
     */
    public function getGroupsByClientId(int $clientId): array {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * delete all redirect URI from a given client
     *
     * @param int $id
     */
    public function deleteByClientId(int $id) {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->tableName)
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * delete mapping entry by id
     *
     * @param int $id
     */
    public function deleteById(int $id) {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * delete all groups that do not exist any more
     *
     */
    public function cleanUp() {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName);
        $usedGroups = $this->findEntities($qb);
        foreach ($usedGroups as $group) {
            if (!$this->groupManager->groupExists($group->getGroupId())) {
                $this->deleteById($group->getId());
            }
        }
    }
}
