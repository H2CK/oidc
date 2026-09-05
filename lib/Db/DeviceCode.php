<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Timill
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getClientId()
 * @method void setClientId(int $clientId)
 * @method string getHashedDeviceCode()
 * @method void setHashedDeviceCode(string $hashedDeviceCode)
 * @method string getHashedUserCode()
 * @method void setHashedUserCode(string $hashedUserCode)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getExpiresAt()
 * @method void setExpiresAt(int $expiresAt)
 * @method int getIntervalSeconds()
 * @method void setIntervalSeconds(int $intervalSeconds)
 * @method int getLastPolledAt()
 * @method void setLastPolledAt(int $lastPolledAt)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getUserId()
 * @method void setUserId(string|null $userId)
 * @method int getConsumedAt()
 * @method void setConsumedAt(int $consumedAt)
 */
class DeviceCode extends Entity {
	public const STATUS_PENDING = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_DENIED = 'denied';
	public const STATUS_CONSUMED = 'consumed';

	public $id;
	protected $clientId;
	protected $hashedDeviceCode;
	protected $hashedUserCode;
	protected $scope;
	protected $createdAt;
	protected $expiresAt;
	protected $intervalSeconds;
	protected $lastPolledAt;
	protected $status;
	protected $userId;
	protected $consumedAt;

	public function __construct() {
		$this->addType('id', 'int');
		$this->addType('clientId', 'int');
		$this->addType('hashedDeviceCode', 'string');
		$this->addType('hashedUserCode', 'string');
		$this->addType('scope', 'string');
		$this->addType('createdAt', 'int');
		$this->addType('expiresAt', 'int');
		$this->addType('intervalSeconds', 'int');
		$this->addType('lastPolledAt', 'int');
		$this->addType('status', 'string');
		$this->addType('userId', 'string');
		$this->addType('consumedAt', 'int');
	}
}
