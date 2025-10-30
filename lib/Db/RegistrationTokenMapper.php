<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RegistrationToken>
 */
class RegistrationTokenMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'oidc_reg_tokens', RegistrationToken::class);
	}

	/**
	 * Get a registration token by its token string
	 *
	 * @param string $token The token string
	 * @return RegistrationToken
	 * @throws DoesNotExistException if token not found
	 */
	public function getByToken(string $token): RegistrationToken {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		return $this->findEntity($qb);
	}

	/**
	 * Get the current (most recent) token for a client
	 *
	 * @param int $clientId The client ID
	 * @return RegistrationToken|null
	 */
	public function getCurrentToken(int $clientId): ?RegistrationToken {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Delete all registration tokens for a client
	 *
	 * @param int $clientId The client ID
	 */
	public function deleteByClientId(int $clientId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Clean up expired registration tokens
	 *
	 * @param int $expiryThreshold Unix timestamp - tokens expiring before this will be deleted
	 */
	public function cleanUp(int $expiryThreshold): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($expiryThreshold, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('expires_at'))
			->executeStatement();
	}
}
