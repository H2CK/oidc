<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Timill
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Tests\Integration;

use OCA\OIDCIdentityProvider\Db\DeviceCode;
use OCA\OIDCIdentityProvider\Db\DeviceCodeMapper;
use OCP\Server;

#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class DeviceCodeMapperIntegrationTest extends \Test\TestCase {
	private DeviceCodeMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = Server::get(DeviceCodeMapper::class);
	}

	public function testDeviceCodeLifecycleAndPollingThrottle(): void {
		$deviceCode = 'test-device-code-' . bin2hex(random_bytes(8));
		$userCode = strtoupper(bin2hex(random_bytes(4)));

		$entity = new DeviceCode();
		$entity->setClientId(987654);
		$entity->setHashedDeviceCode(hash('sha512', $deviceCode));
		$entity->setHashedUserCode(hash('sha512', $userCode));
		$entity->setScope('openid profile email');
		$entity->setCreatedAt(1000);
		$entity->setExpiresAt(2000);
		$entity->setIntervalSeconds(5);
		$entity->setLastPolledAt(0);
		$entity->setStatus(DeviceCode::STATUS_PENDING);
		$entity->setUserId(null);
		$entity->setConsumedAt(0);
		$entity = $this->mapper->insert($entity);

		try {
			$stored = $this->mapper->findByDeviceCode($deviceCode);
			$this->assertNotNull($stored);
			$this->assertSame($entity->getId(), $stored->getId());

			$formattedUserCode = substr($userCode, 0, 4) . '-' . substr($userCode, 4);
			$this->assertSame($entity->getId(), $this->mapper->findByUserCode(strtolower($formattedUserCode))?->getId());

			$this->assertTrue($this->mapper->recordPoll($stored, 1010));
			$stored = $this->mapper->findByDeviceCode($deviceCode);
			$this->assertNotNull($stored);
			$this->assertFalse($this->mapper->recordPoll($stored, 1011));

			$stored = $this->mapper->findByDeviceCode($deviceCode);
			$this->assertNotNull($stored);
			$this->assertSame(5, $stored->getIntervalSeconds());
			$this->assertSame(1011, $stored->getLastPolledAt());
			$this->assertTrue($this->mapper->recordPoll($stored, 1016));

			$this->assertTrue($this->mapper->markApproved($stored, 'alice'));
			$stored = $this->mapper->findByDeviceCode($deviceCode);
			$this->assertNotNull($stored);
			$this->assertSame(DeviceCode::STATUS_APPROVED, $stored->getStatus());
			$this->assertSame('alice', $stored->getUserId());

			$this->assertTrue($this->mapper->markConsumed($stored, 1020));
			$stored = $this->mapper->findByDeviceCode($deviceCode);
			$this->assertNotNull($stored);
			$this->assertSame(DeviceCode::STATUS_CONSUMED, $stored->getStatus());
			$this->assertSame(1020, $stored->getConsumedAt());
		} finally {
			$this->mapper->delete($entity);
		}
	}

	public function testExpiredDeviceCodesAreCleanedUp(): void {
		$deviceCode = 'expired-device-code-' . bin2hex(random_bytes(8));
		$entity = new DeviceCode();
		$entity->setClientId(987654);
		$entity->setHashedDeviceCode(hash('sha512', $deviceCode));
		$entity->setHashedUserCode(hash('sha512', strtoupper(bin2hex(random_bytes(4)))));
		$entity->setScope('openid');
		$entity->setCreatedAt(1000);
		$entity->setExpiresAt(1100);
		$entity->setIntervalSeconds(5);
		$entity->setLastPolledAt(0);
		$entity->setStatus(DeviceCode::STATUS_PENDING);
		$entity->setUserId(null);
		$entity->setConsumedAt(0);
		$this->mapper->insert($entity);

		$this->mapper->cleanUp(1101);

		$this->assertNull($this->mapper->findByDeviceCode($deviceCode));
	}
}
