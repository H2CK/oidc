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

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
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
     * delete all access token from a given client
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
     * delete all access token from a given user
     *
     * @param string $id
     */
    public function deleteByUserId(string $id) {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($id)));
        $qb->executeStatement();
    }

    /**
     * delete all expired access tokens
     *
     */
    public function cleanUp() {
        $expireTime = (int)$this->appConfig->getAppValue(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, Application::DEFAULT_EXPIRE_TIME);
        $refreshExpireTime = $this->appConfig->getAppValue(Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME, Application::DEFAULT_REFRESH_EXPIRE_TIME);
        if ($refreshExpireTime !== 'never') {
            // keep the token until its refresh token has expired
            $expireTime = max($expireTime, (int)$refreshExpireTime);
        }
        $timeLimit = $this->time->getTime() - $expireTime;

        // refreshed < $timeLimit
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->tableName)
            ->where($qb->expr()->lt('refreshed', $qb->createNamedParameter($timeLimit, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
