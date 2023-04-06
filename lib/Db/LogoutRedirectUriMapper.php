<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2023 Thorsten Jagel <dev@jagel.net>
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
	 * @return LogoutRedirectUri[]
	 * @throws RedirectUriNotFoundException
	 */
	public function getByRedirectUri(string $redirectUri): array {
		$qb = $this->db->getQueryBuilder();
		$qb
			->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('redirect_uri', $qb->createNamedParameter($redirectUri)));

		try {
			$redirectUriEntry = $this->findEntity($qb);
		} catch (IMapperException $e) {
			throw new RedirectUriTokenNotFoundException('Could not find redirect URI', 0, $e);
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
