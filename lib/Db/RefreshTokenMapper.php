<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<RefreshToken> */
class RefreshTokenMapper extends QBMapper {
    /** Retain consumed hashes for seven days of replay detection, even with never-expiring grants. */
    public const USED_RETENTION = 7 * 24 * 60 * 60;
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'oidc_refresh_tokens', RefreshToken::class);
    }

    public function createForAccessToken(int $accessTokenId, string $token, int $created): RefreshToken {
        $refreshToken = new RefreshToken();
        $refreshToken->setAccessTokenId($accessTokenId);
        $refreshToken->setHashedToken(hash('sha512', $token));
        $refreshToken->setCreated($created);
        $refreshToken->setUsedAt(0);

        return $this->insert($refreshToken);
    }

    public function findByToken(string $token): ?RefreshToken {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                'hashed_token',
                $qb->createNamedParameter(hash('sha512', $token))
            ));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /** Atomically consume one refresh-token generation. */
    public function markUsed(RefreshToken $refreshToken, int $usedAt): bool {
        $qb = $this->db->getQueryBuilder();
        $updated = $qb->update($this->getTableName())
            ->set('used_at', $qb->createNamedParameter($usedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($refreshToken->getId(), IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('used_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        if ($updated === 1) {
            $refreshToken->setUsedAt($usedAt);
            return true;
        }

        return false;
    }

    public function deleteByAccessTokenId(int $accessTokenId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq(
                'access_token_id',
                $qb->createNamedParameter($accessTokenId, IQueryBuilder::PARAM_INT)
            ))
            ->executeStatement();
    }

    public function cleanUp(int $now): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->gt('used_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('used_at', $qb->createNamedParameter($now - self::USED_RETENTION, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        // Repair pre-existing orphans as well as installations where foreign
        // key enforcement is disabled. Use bounded batches and portable joins.
        do {
            $qb = $this->db->getQueryBuilder();
            $qb->select('r.id')->from($this->getTableName(), 'r')
                ->leftJoin('r', 'oidc_access_tokens', 'a', $qb->expr()->eq('r.access_token_id', 'a.id'))
                ->where($qb->expr()->isNull('a.id'))->setMaxResults(500);
            $result = $qb->executeQuery();
            try {
                $ids = [];
                while (($id = $result->fetchOne()) !== false) {
                    $ids[] = (int)$id;
                }
            } finally {
                $result->closeCursor();
            }
            foreach ($ids as $id) {
                $delete = $this->db->getQueryBuilder();
                $delete->delete($this->getTableName())
                    ->where($delete->expr()->eq('id', $delete->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
            }
        } while (count($ids) === 500);
    }
}
