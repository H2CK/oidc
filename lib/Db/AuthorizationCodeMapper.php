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

/**
 * @template-extends QBMapper<AuthorizationCode>
 */
class AuthorizationCodeMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'oidc_auth_codes', AuthorizationCode::class);
    }

    public function createForAccessToken(int $accessTokenId, string $code, int $created): AuthorizationCode {
        $authorizationCode = new AuthorizationCode();
        $authorizationCode->setAccessTokenId($accessTokenId);
        $authorizationCode->setHashedCode(hash('sha512', $code));
        $authorizationCode->setCreated($created);
        $authorizationCode->setUsedAt(0);

        return $this->insert($authorizationCode);
    }

    public function findByCode(string $code): ?AuthorizationCode {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('hashed_code', $qb->createNamedParameter(hash('sha512', $code))));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    public function markUsed(AuthorizationCode $authorizationCode, int $usedAt): bool {
        $qb = $this->db->getQueryBuilder();
        $updated = $qb
            ->update($this->getTableName())
            ->set('used_at', $qb->createNamedParameter($usedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($authorizationCode->getId(), IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('used_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        if ($updated === 1) {
            $authorizationCode->setUsedAt($usedAt);
            return true;
        }

        return false;
    }

    public function cleanUp(int $unusedCreatedBefore, ?int $usedBefore): void {
        $qb = $this->db->getQueryBuilder();
        $where = $qb->expr()->andX(
            $qb->expr()->eq('used_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
            $qb->expr()->lt('created', $qb->createNamedParameter($unusedCreatedBefore, IQueryBuilder::PARAM_INT))
        );

        if ($usedBefore !== null) {
            $where = $qb->expr()->orX(
                $where,
                $qb->expr()->andX(
                    $qb->expr()->gt('used_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->lt('used_at', $qb->createNamedParameter($usedBefore, IQueryBuilder::PARAM_INT))
                )
            );
        }

        $qb
            ->delete($this->getTableName())
            ->where($where)
            ->executeStatement();
    }
}
