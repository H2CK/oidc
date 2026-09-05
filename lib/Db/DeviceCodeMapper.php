<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Timill
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<DeviceCode> */
class DeviceCodeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'oidc_device_codes', DeviceCode::class);
	}

	public static function normalizeUserCode(string $userCode): string {
		return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', $userCode));
	}

	public function findByDeviceCode(string $deviceCode): ?DeviceCode {
		return $this->findByHash('hashed_device_code', hash('sha512', $deviceCode));
	}

	public function findByUserCode(string $userCode): ?DeviceCode {
		return $this->findByHash('hashed_user_code', hash('sha512', self::normalizeUserCode($userCode)));
	}

	private function findByHash(string $column, string $hash): ?DeviceCode {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq($column, $qb->createNamedParameter($hash)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	public function markApproved(DeviceCode $deviceCode, string $userId): bool {
		return $this->updateStatus(
			$deviceCode,
			DeviceCode::STATUS_PENDING,
			DeviceCode::STATUS_APPROVED,
			['user_id' => $userId]
		);
	}

	public function markDenied(DeviceCode $deviceCode): bool {
		return $this->updateStatus($deviceCode, DeviceCode::STATUS_PENDING, DeviceCode::STATUS_DENIED);
	}

	public function markConsumed(DeviceCode $deviceCode, int $consumedAt): bool {
		return $this->updateStatus(
			$deviceCode,
			DeviceCode::STATUS_APPROVED,
			DeviceCode::STATUS_CONSUMED,
			['consumed_at' => $consumedAt]
		);
	}

	/** @param array<string,int|string> $extraValues */
	private function updateStatus(
		DeviceCode $deviceCode,
		string $expectedStatus,
		string $newStatus,
		array $extraValues = [],
	): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($newStatus))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($deviceCode->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($expectedStatus)));

		foreach ($extraValues as $column => $value) {
			$type = is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR;
			$qb->set($column, $qb->createNamedParameter($value, $type));
		}

		return $qb->executeStatement() === 1;
	}

	/**
	 * Record a compliant poll. False means the client polled before its current
	 * interval elapsed; in that case RFC 8628 requires a slow_down response.
	 */
	public function recordPoll(DeviceCode $deviceCode, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		$earliestAllowed = $now - max(1, $deviceCode->getIntervalSeconds());
		$updated = $qb->update($this->getTableName())
			->set('last_polled_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($deviceCode->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('last_polled_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
				$qb->expr()->lte('last_polled_at', $qb->createNamedParameter($earliestAllowed, IQueryBuilder::PARAM_INT))
			))
			->executeStatement();

		if ($updated === 1) {
			return true;
		}

		$tooEarly = $this->db->getQueryBuilder();
		$tooEarly->update($this->getTableName())
			->set('last_polled_at', $tooEarly->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($tooEarly->expr()->eq('id', $tooEarly->createNamedParameter($deviceCode->getId(), IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return false;
	}

	public function cleanUp(int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function deleteByClientId(int $clientId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
