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
}
