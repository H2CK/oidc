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

	/**
	 * Return a consumed code to approved when the tokens it was consumed for
	 * could not be issued, so the client can retry instead of being told the
	 * code was already used.
	 */
	public function revertConsumed(DeviceCode $deviceCode): bool {
		return $this->updateStatus(
			$deviceCode,
			DeviceCode::STATUS_CONSUMED,
			DeviceCode::STATUS_APPROVED,
			['consumed_at' => 0]
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
	 * Interval advertised to the client, and the value the interval returns to
	 * once a client is polling acceptably again.
	 */
	public const INITIAL_INTERVAL_SECONDS = 5;

	/**
	 * Seconds added to the polling interval for each RFC 8628 slow_down.
	 */
	public const SLOW_DOWN_INCREMENT_SECONDS = 5;

	/**
	 * Upper bound for interval escalation, so the window cannot recede as fast as
	 * a polling client advances.
	 */
	public const MAX_INTERVAL_SECONDS = 15;

	/**
	 * Absorbs jitter between polls. The timestamp is taken when the request is
	 * processed, so two polls a full interval apart on the wire can be recorded
	 * fractionally closer together.
	 */
	private const POLL_TOLERANCE_SECONDS = 1;

	/**
	 * Record a compliant poll. False means the client polled before its interval
	 * elapsed and RFC 8628 requires a slow_down response.
	 *
	 * A rejected poll leaves last_polled_at on the last accepted poll, so a
	 * client that backs off is guaranteed to get back in, and the escalation is
	 * capped and undone once the client polls acceptably again. Advancing the
	 * anchor on a rejected poll while also raising the interval would move the
	 * window away exactly as fast as a client polling at a fixed cadence
	 * approaches it, leaving the code unusable until it expired.
	 */
	public function recordPoll(DeviceCode $deviceCode, int $now): bool {
		$interval = max(1, $deviceCode->getIntervalSeconds());
		$qb = $this->db->getQueryBuilder();
		$earliestAllowed = $now - $interval + self::POLL_TOLERANCE_SECONDS;
		$updated = $qb->update($this->getTableName())
			->set('last_polled_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($deviceCode->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('last_polled_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
				$qb->expr()->lte('last_polled_at', $qb->createNamedParameter($earliestAllowed, IQueryBuilder::PARAM_INT))
			))
			->executeStatement();

		if ($updated === 1) {
			$deviceCode->setLastPolledAt($now);
			if ($deviceCode->getIntervalSeconds() > self::INITIAL_INTERVAL_SECONDS) {
				$reset = $this->db->getQueryBuilder();
				$reset->update($this->getTableName())
					->set('interval_seconds', $reset->createNamedParameter(self::INITIAL_INTERVAL_SECONDS, IQueryBuilder::PARAM_INT))
					->where($reset->expr()->eq('id', $reset->createNamedParameter($deviceCode->getId(), IQueryBuilder::PARAM_INT)))
					->executeStatement();
				$deviceCode->setIntervalSeconds(self::INITIAL_INTERVAL_SECONDS);
			}
			return true;
		}

		$newInterval = min($interval + self::SLOW_DOWN_INCREMENT_SECONDS, self::MAX_INTERVAL_SECONDS);
		if ($newInterval !== $deviceCode->getIntervalSeconds()) {
			$tooEarly = $this->db->getQueryBuilder();
			$tooEarly->update($this->getTableName())
				->set('interval_seconds', $tooEarly->createNamedParameter($newInterval, IQueryBuilder::PARAM_INT))
				->where($tooEarly->expr()->eq('id', $tooEarly->createNamedParameter($deviceCode->getId(), IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$deviceCode->setIntervalSeconds($newInterval);
		}
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
