<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCA\OIDCIdentityProvider\Exceptions\RedirectUriNotFoundException;
use OCP\AppFramework\Db\IMapperException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Services\IAppConfig;

/**
 * @template-extends QBMapper<LogoutRedirectUri>
 */
class LogoutRedirectUriMapper extends QBMapper {
    /** @var ITimeFactory */
    private $time;
    /** @var IAppConfig */
    private $appConfig;

    public function __construct(IDBConnection $db, ITimeFactory $time, IAppConfig $appConfig) {
        parent::__construct($db, 'oidc_loredirect_uris');
        $this->time = $time;
        $this->appConfig = $appConfig;
    }

    /** @return LogoutRedirectUri[] */
    public function getAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->tableName);
        return $this->findEntities($qb);
    }

    /** @return LogoutRedirectUri[] */
    public function getGlobal(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->isNull('client_id'));
        return $this->findEntities($qb);
    }

    /** @return LogoutRedirectUri[] */
    public function getByClientId(int $clientId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    /**
     * Return the RP-specific allow-list when present. Only if the RP has no
     * dedicated entries at all, fall back to the legacy global allow-list.
     *
     * @return LogoutRedirectUri[]
     */
    public function getEffectiveByClientId(int $clientId): array {
        $clientUris = $this->getByClientId($clientId);
        return $clientUris !== [] ? $clientUris : $this->getGlobal();
    }

    public function getById(int $id): LogoutRedirectUri {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (IMapperException $e) {
            throw new RedirectUriNotFoundException('could not find redirect URI with id ' . $id, 0, $e);
        }
    }

    /**
     * Legacy lookup. Prefer scoped methods for authorization decisions.
     */
    public function getByRedirectUri(string $redirectUri): LogoutRedirectUri {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('redirect_uri', $qb->createNamedParameter($redirectUri)));
        try {
            return $this->findEntity($qb);
        } catch (IMapperException $e) {
            throw new RedirectUriNotFoundException('Could not find redirect URI', 0, $e);
        }
    }

    public function deleteByClientId(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->tableName)
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function deleteOneById(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Delete an exact URI in one scope. Null preserves the historical CLI/API
     * behaviour by addressing only the global allow-list.
     */
    public function deleteByRedirectUri(string $redirectUri, ?int $clientId = null): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->tableName)
            ->where($qb->expr()->eq('redirect_uri', $qb->createNamedParameter($redirectUri)));
        if ($clientId === null) {
            $qb->andWhere($qb->expr()->isNull('client_id'));
        } else {
            $qb->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));
        }
        return $qb->executeStatement() > 0;
    }
}
