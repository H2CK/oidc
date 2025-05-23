<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCP\AppFramework\Db\Entity;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCP\AppFramework\Db\IMapperException;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Security\ISecureRandom;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * @template-extends QBMapper<Client>
 */
class ClientMapper extends QBMapper {
    /** @var ITimeFactory */
    private $time;
    /** @var IAppConfig */
    private $appConfig;
    /** @var RedirectUriMapper */
    private $redirectUriMapper;
    /** @var ISecureRandom */
    private $secureRandom;
    /** @var LoggerInterface */
    private $logger;

    public const ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * @param IDBConnection $db
     */
    public function __construct(
        IDBConnection $db,
        ITimeFactory $time,
        IAppConfig $appConfig,
        RedirectUriMapper $redirectUriMapper,
        ISecureRandom $secureRandom,
        LoggerInterface $logger
    ) {
        parent::__construct($db, 'oidc_clients');
        $this->time = $time;
        $this->appConfig = $appConfig;
        $this->redirectUriMapper = $redirectUriMapper;
        $this->secureRandom = $secureRandom;
        $this->logger = $logger;
    }

    public function insert(Entity $entity): Entity {
        if(!$entity->getClientIdentifier()) {
            $entity->setClientIdentifier($this->secureRandom->generate(64, self::ALNUM));
        }
        if(!$entity->getSecret()) {
            $entity->setSecret($this->secureRandom->generate(64, self::ALNUM));
        }

        $entity = parent::insert($entity);

        // insert related redirect uris
        $uris = $entity->getRedirectUris();
        if(!empty($uris)) {
            foreach ($uris as $uri) {
                $redirectUri = new RedirectUri();
                $redirectUri->setClientId($entity->getId());
                $redirectUri->setRedirectUri($uri);
                $this->redirectUriMapper->insert($redirectUri);
            }
        }

        return $entity;
    }

    public function delete(Entity $entity): Entity {
        // remove redirect uris first
        $uris = $this->redirectUriMapper->getByClientId($entity->getId());
        foreach ($uris as $uri) {
            $this->redirectUriMapper->delete($uri);
        }
        // remove the client
        $entity = parent::delete($entity);
        return $entity;
    }

    protected function mapRowToEntity(array $row): Entity {
        $entity = parent::mapRowToEntity($row);
        $entity->setRedirectUris($this->redirectUriMapper->getByClientId($entity->getId()));
        return $entity;
    }

    /**
     * @param string $clientIdentifier
     * @return Client
     * @throws ClientNotFoundException
     */
    public function getByIdentifier(string $clientIdentifier): ?Client {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('client_identifier', $qb->createNamedParameter($clientIdentifier)));

        try {
            return $this->findEntity($qb);
        } catch (IMapperException $e) {
            throw new ClientNotFoundException('could not find client with client_id '. $clientIdentifier, 0, null);
        }
    }

    /**
     * @param int $id internal id of the client
     * @return Client
     * @throws ClientNotFoundException
     */
    public function getByUid(int $id): Client {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (IMapperException $e) {
            throw new ClientNotFoundException('could not find client with id '.$id, 0, null);
        }
    }

    /**
     * @return Client[]
     */
    public function getClients(): array {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName);

        return $this->findEntities($qb);
    }

    /**
     * @return int Number of DCR clients
     */
    public function getNumDcrClients(): int {
        $qb = $this->db->getQueryBuilder();

        $qb
            ->select('*')
            ->from($this->tableName)
            // ->select($qb->createFunction('COUNT(`id`)'))
            ->where($qb->expr()->eq('dcr', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        return $qb->executeQuery()->rowCount();
    }


    /**
     * Deletes a client by its identifier.
     *
     * @param string $clientIdentifier
     * @throws ClientNotFoundException
     */
    public function deleteByIdentifier(string $clientIdentifier): bool {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->tableName)
            ->where($qb->expr()->eq('client_identifier', $qb->createNamedParameter($clientIdentifier)));

        return boolval($qb->executeStatement());
    }

    /**
     * delete all expired clients
     *
     */
    public function cleanUp() {
        $qb = $this->db->getQueryBuilder();
        $timeLimit = $this->time->getTime() - (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME, Application::DEFAULT_CLIENT_EXPIRE_TIME);

        $where = $qb->expr()->andX(
            $qb->expr()->lt('issued_at', $qb->createNamedParameter($timeLimit, IQueryBuilder::PARAM_INT)),
            $qb->expr()->eq('dcr', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
        );

        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($where);

        $entities = $this->findEntities($qb);

        foreach ($entities as $entity) {
            // Delete the corresponding redirect uris
            $this->redirectUriMapper->deleteByClientId($entity->getId());
        }

        $qb = $this->db->getQueryBuilder();
        // issued_at < $timeLimit
        $where = $qb->expr()->andX(
            $qb->expr()->lt('issued_at', $qb->createNamedParameter($timeLimit, IQueryBuilder::PARAM_INT)),
            $qb->expr()->eq('dcr', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
        );
        $qb
            ->delete($this->tableName)
            ->where($where);
        $qb->executeStatement();
    }
}
