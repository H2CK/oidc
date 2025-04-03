<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2024 Thorsten Jagel <dev@jagel.net>
 *
 * @author Thorsten Jagel <dev@jagel.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\OIDCIdentityProvider\Db;

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
    ) {
        parent::__construct($db, 'oidc_clients');
        $this->time = $time;
        $this->appConfig = $appConfig;
        $this->redirectUriMapper = $redirectUriMapper;
        $this->secureRandom = $secureRandom;
    }

    public function insert(Entity $entity): Entity {
        if(!$entity->getClientIdentifier())
            $entity->setClientIdentifier($this->secureRandom->generate(64, self::ALNUM));
        if(!$entity->getSecret())
            $entity->setSecret($this->secureRandom->generate(64, self::ALNUM));

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
        foreach ($uris as $uri)
            $this->redirectUriMapper->delete($uri);
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

        return boolval($qb->execute());
    }

    /**
     * delete all expired clients
     *
     */
    public function cleanUp() {
        $qb = $this->db->getQueryBuilder();
        $timeLimit = $this->time->getTime() - $this->appConfig->getAppValue('client_expire_time', '3600');

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
