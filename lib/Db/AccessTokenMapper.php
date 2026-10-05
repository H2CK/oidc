<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\IMapperException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Services\IAppConfig;

/**
 * @template-extends QBMapper<AccessToken>
 */
class AccessTokenMapper extends QBMapper {
    /** @var ITimeFactory */
    private $time;
    /** @var IAppConfig */
    private $appConfig;

    /**
     * @param IDBConnection $db
     */
    public function __construct(IDBConnection $db,
                                ITimeFactory $time,
                                IAppConfig $appConfig) {
        parent::__construct($db, 'oidc_access_tokens');
        $this->time = $time;
        $this->appConfig = $appConfig;
    }

    /**
     * @param string $code
     * @return AccessToken
     * @throws AccessTokenNotFoundException
     */
    public function getByCode(string $code): AccessToken {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('hashed_code', $qb->createNamedParameter(hash('sha512', $code))));

        try {
            $token = $this->findEntity($qb);
        } catch (IMapperException $e) {
            throw new AccessTokenNotFoundException('Could not find access token', 0, $e);
        }

        return $token;
    }

    /**
     * Atomically reserve rotation of a refresh token issued before the
     * dedicated refresh-token table existed.
     */
    public function rotateLegacyRefreshToken(int $id, string $presentedToken, string $replacementToken): bool {
        $qb = $this->db->getQueryBuilder();
        $updated = $qb->update($this->tableName)
            ->set('hashed_code', $qb->createNamedParameter(hash('sha512', $replacementToken)))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq(
                'hashed_code',
                $qb->createNamedParameter(hash('sha512', $presentedToken))
            ))
            ->executeStatement();

        return $updated === 1;
    }

    /**
     * @param string $code
     * @return AccessToken
     * @throws AccessTokenNotFoundException
     */
    public function getByAccessToken(string $accessToken): AccessToken {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('access_token', $qb->createNamedParameter($accessToken)));

        try {
            $token = $this->findEntity($qb);
        } catch (IMapperException $e) {
            throw new AccessTokenNotFoundException('Could not find access token', 0, $e);
        }

        return $token;
    }

    /**
     * @param int $id
     * @return AccessToken
     * @throws AccessTokenNotFoundException
     */
    public function getById(int $id): AccessToken {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        try {
            $token = $this->findEntity($qb);
        } catch (IMapperException $e) {
            throw new AccessTokenNotFoundException('Could not find access token', 0, $e);
        }

        return $token;
    }


    /**
     * Start the short transaction used to serialize credential issuance with
     * revocation of the subject-token row.
     */
    public function beginTokenExchangeTransaction(): void {
        $this->db->beginTransaction();
    }

    public function commitTokenExchangeTransaction(): void {
        $this->db->commit();
    }

    public function rollBackTokenExchangeTransaction(): void {
        $this->db->rollBack();
    }

    /**
     * Acquire an issuance/revocation lock on an access-token row for the current
     * transaction.
     *
     * MySQL/MariaDB, PostgreSQL and Oracle use SELECT ... FOR UPDATE so the
     * primary-key row remains exclusively locked until commit/rollback. SQLite
     * has no SELECT FOR UPDATE, so a no-op UPDATE is used there to enter its
     * serialized writer transaction before the row is re-read.
     *
     * @throws AccessTokenNotFoundException
     */
    public function lockTokenExchangeSubject(int $id): AccessToken {
        if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
            $qb = $this->db->getQueryBuilder();
            $qb->update($this->tableName)
                ->set('id', 'id')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
        } else {
            $result = $this->db->executeQuery(
                'SELECT id FROM *PREFIX*oidc_access_tokens WHERE id = ? FOR UPDATE',
                [$id],
                [IQueryBuilder::PARAM_INT]
            );
            try {
                if ($result->fetch() === false) {
                    throw new AccessTokenNotFoundException('Could not lock access token');
                }
            } finally {
                $result->closeCursor();
            }
        }

        return $this->getById($id);
    }

    /** @return list<AccessToken> */
    public function getByParentTokenId(int $parentTokenId): array {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('parent_token_id', $qb->createNamedParameter($parentTokenId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    /**
     * Delete an access token and every RFC 8693 token descended from it.
     * The visited set protects against manually corrupted lineage data.
     */
    public function delete(Entity $entity): Entity {
        if ($entity instanceof AccessToken && (int)$entity->getId() > 0) {
            $visited = [];
            $this->deleteDescendants((int)$entity->getId(), $visited);
            $this->deleteGrantCredentials((int)$entity->getId());
        }
        return parent::delete($entity);
    }

    /** @param array<int,bool> $visited */
    private function deleteDescendants(int $parentTokenId, array &$visited): void {
        if (isset($visited[$parentTokenId])) {
            return;
        }
        $visited[$parentTokenId] = true;
        foreach ($this->getByParentTokenId($parentTokenId) as $child) {
            $childId = (int)$child->getId();
            if ($childId > 0) {
                $this->deleteDescendants($childId, $visited);
                $this->deleteGrantCredentials($childId);
            }
            parent::delete($child);
        }
    }

    private function deleteGrantCredentials(int $accessTokenId): void {
        foreach (['oidc_refresh_tokens', 'oidc_authorization_codes'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)
                ->where($qb->expr()->eq(
                    'access_token_id',
                    $qb->createNamedParameter($accessTokenId, IQueryBuilder::PARAM_INT)
                ))
                ->executeStatement();
        }
    }

    private function deleteEntitiesWithDescendants(IQueryBuilder $qb): void {
        $tokens = $this->findEntities($qb);
        $selectedIds = [];
        foreach ($tokens as $token) {
            $selectedIds[(int)$token->getId()] = true;
        }

        // Delete only roots of the selected subset. A selected descendant is
        // already removed by the recursive cascade of its selected ancestor.
        foreach ($tokens as $token) {
            $parentTokenId = (int)($token->getParentTokenId() ?? 0);
            if ($parentTokenId > 0 && isset($selectedIds[$parentTokenId])) {
                continue;
            }
            $this->delete($token);
        }
    }


    /**
     * delete all access token from a given client
     *
     * @param int $id
     */
    public function deleteByClientId(int $id) {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $this->deleteEntitiesWithDescendants($qb);
    }

    /**
     * delete all access token for a given user and client
     *
     * @param string $userId
     * @param int $clientId
     */
    public function deleteByUserAndClient(string $userId, int $clientId): void {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));
        $this->deleteEntitiesWithDescendants($qb);
    }

    /**
     * delete all access token from a given user
     *
     * @param string $id
     */
    public function deleteByUserId(string $id) {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($id)));
        $this->deleteEntitiesWithDescendants($qb);
    }

    /**
     * delete all expired access tokens
     *
     */
    public function cleanUp() {
        $refreshExpireTime = $this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME, Application::DEFAULT_REFRESH_EXPIRE_TIME);
        if ($refreshExpireTime === 'never') {
            $now = $this->time->getTime();
            $expireTime = (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, Application::DEFAULT_EXPIRE_TIME);
            $qb = $this->db->getQueryBuilder();
            $qb->select('a.*')->from($this->tableName, 'a')
                ->leftJoin('a', 'oidc_refresh_tokens', 'r', $qb->expr()->andX(
                    $qb->expr()->eq('r.access_token_id', 'a.id'),
                    $qb->expr()->eq('r.used_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                ))
                ->leftJoin('a', 'oidc_authorization_codes', 'c', $qb->expr()->andX(
                    $qb->expr()->eq('c.access_token_id', 'a.id'),
                    $qb->expr()->eq('c.used_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->gt('c.created', $qb->createNamedParameter($now - Application::AUTHORIZATION_CODE_LIFETIME, IQueryBuilder::PARAM_INT))
                ))
                ->where($qb->expr()->isNull('r.id'))
                ->andWhere($qb->expr()->isNull('c.id'))
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->eq('a.legacy_refresh_token', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
                    $qb->expr()->isNull('a.legacy_refresh_token')
                ))
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->andX(
                        $qb->expr()->gt('a.expires_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
                        $qb->expr()->lte('a.expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ),
                    $qb->expr()->andX(
                        $qb->expr()->orX($qb->expr()->isNull('a.expires_at'),
                            $qb->expr()->eq('a.expires_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))),
                        $qb->expr()->lte('a.refreshed', $qb->createNamedParameter($now - $expireTime, IQueryBuilder::PARAM_INT))
                    )
                ));
            $this->deleteEntitiesWithDescendants($qb);
            return;
        }

        // keep the token until its refresh token has expired
        $expireTime = max(
            (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, Application::DEFAULT_EXPIRE_TIME),
            (int)$refreshExpireTime
        );
        $timeLimit = $this->time->getTime() - $expireTime;

        // refreshed < $timeLimit
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->lt('refreshed', $qb->createNamedParameter($timeLimit, IQueryBuilder::PARAM_INT)));
        $this->deleteEntitiesWithDescendants($qb);
    }
}
