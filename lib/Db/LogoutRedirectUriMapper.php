<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
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
 * @template-extends QBMapper<AccessToken>
 */
class LogoutRedirectUriMapper extends QBMapper {
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
        parent::__construct($db, 'oidc_loredirect_uris');
        $this->time = $time;
        $this->appConfig = $appConfig;
    }


    /**
     * @param string $id
     * @return LogoutRedirectUri[]
     * @throws RedirectUriNotFoundException
     */
    public function getAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName);

        return $this->findEntities($qb);
    }

    /**
     * @param int $id id of the redirect URI
     * @return LogoutRedirectUri
     * @throws RedirectUriNotFoundException
     */
    public function getById(int $id): LogoutRedirectUri {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        try {
            $redirectUri = $this->findEntity($qb);
        } catch (IMapperException $e) {
            throw new RedirectUriNotFoundException('could not find redirect URI with id '.$id, 0, $e);
        }
        return $redirectUri;
    }

    /**
     * @param string $redirectUri
     * @return LogoutRedirectUri
     * @throws RedirectUriNotFoundException
     */
    public function getByRedirectUri(string $redirectUri): LogoutRedirectUri {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('redirect_uri', $qb->createNamedParameter($redirectUri)));

        try {
            $redirectUriEntry = $this->findEntity($qb);
        } catch (IMapperException $e) {
            throw new RedirectUriNotFoundException('Could not find redirect URI', 0, $e);
        }

        return $redirectUriEntry;
    }

    /**
     * delete one redirect URI by id
     *
     * @param int $id
     */
    public function deleteOneById(int $id) {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
