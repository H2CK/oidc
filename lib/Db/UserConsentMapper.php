<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<UserConsent>
 */
class UserConsentMapper extends QBMapper {

    /**
     * @param IDBConnection $db
     */
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'oidc_user_consents');
    }

    /**
     * Find consent by user ID and client ID
     *
     * @param string $userId
     * @param int $clientId
     * @return UserConsent|null
     */
    public function findByUserAndClient(string $userId, int $clientId): ?UserConsent {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find all consents for a given user
     *
     * @param string $userId
     * @return UserConsent[]
     */
    public function findByUserId(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntities($qb);
    }

    /**
     * Create or update a consent
     *
     * @param UserConsent $consent
     * @return UserConsent
     */
    public function createOrUpdate(UserConsent $consent): UserConsent {
        $existing = $this->findByUserAndClient($consent->getUserId(), $consent->getClientId());

        if ($existing !== null) {
            // Update existing consent
            $existing->setScopesGranted($consent->getScopesGranted());
            $existing->setUpdatedAt($consent->getUpdatedAt());
            $existing->setExpiresAt($consent->getExpiresAt());
            return $this->update($existing);
        } else {
            // Insert new consent
            return $this->insert($consent);
        }
    }

    /**
     * Delete consent by user ID and client ID
     *
     * @param string $userId
     * @param int $clientId
     */
    public function deleteByUserAndClient(string $userId, int $clientId): void {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Delete all consents for a given client (cascade on client deletion)
     *
     * @param int $clientId
     */
    public function deleteByClientId(int $clientId): void {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->tableName)
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Delete all consents for a given user
     *
     * @param string $userId
     */
    public function deleteByUserId(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }

    /**
     * Clean up expired consents
     * Deletes all consents where expires_at is not null and is in the past
     */
    public function cleanUp(): void {
        $currentTime = time();
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->tableName)
            ->where($qb->expr()->isNotNull('expires_at'))
            ->andWhere($qb->expr()->lt('expires_at', $qb->createNamedParameter($currentTime, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
