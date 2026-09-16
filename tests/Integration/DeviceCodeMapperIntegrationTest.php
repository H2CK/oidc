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

			// $now is whole seconds, so a client polling every 5.0s of wall clock
			// yields integer deltas of 5,5,4,5,... The 4s deltas are not abuse.
			$this->assertTrue($this->mapper->recordPoll($stored, 1014));
			$stored = $this->mapper->findByDeviceCode($deviceCode);
			$this->assertNotNull($stored);
			$this->assertSame(5, $stored->getIntervalSeconds());
			$this->assertSame(1014, $stored->getLastPolledAt());

			// A genuinely early poll still gets slow_down and raises the interval,
			// but must leave the anchor on the last ACCEPTED poll.
			$this->assertFalse($this->mapper->recordPoll($stored, 1015));
			$stored = $this->mapper->findByDeviceCode($deviceCode);
			$this->assertNotNull($stored);
			$this->assertSame(10, $stored->getIntervalSeconds());
			$this->assertSame(1014, $stored->getLastPolledAt());

			// A second consecutive early poll increases the interval again (RFC 8628).
			$this->assertFalse($this->mapper->recordPoll($stored, 1016));
			$stored = $this->mapper->findByDeviceCode($deviceCode);
			$this->assertNotNull($stored);
			$this->assertSame(15, $stored->getIntervalSeconds());
			$this->assertSame(1014, $stored->getLastPolledAt());

			// Escalation is capped, otherwise the window recedes as fast as the
			// client advances and the code is locked out until it expires.
			$this->assertFalse($this->mapper->recordPoll($stored, 1017));
			$stored = $this->mapper->findByDeviceCode($deviceCode);
			$this->assertNotNull($stored);
			$this->assertSame(DeviceCodeMapper::MAX_INTERVAL_SECONDS, $stored->getIntervalSeconds());
			$this->assertSame(1014, $stored->getLastPolledAt());

			// Regression guard: a client that keeps polling must get back in, and
			// the escalation is undone so the interval does not creep up for the
			// remaining life of the device code.
			$this->assertTrue($this->mapper->recordPoll($stored, 1030));
			$stored = $this->mapper->findByDeviceCode($deviceCode);
			$this->assertNotNull($stored);
			$this->assertSame(DeviceCodeMapper::INITIAL_INTERVAL_SECONDS, $stored->getIntervalSeconds());
			$this->assertSame(1030, $stored->getLastPolledAt());

			$this->assertTrue($this->mapper->markApproved($stored, 'alice'));
			$stored = $this->mapper->findByDeviceCode($deviceCode);
			$this->assertNotNull($stored);
			$this->assertSame(DeviceCode::STATUS_APPROVED, $stored->getStatus());
			$this->assertSame('alice', $stored->getUserId());

			$this->assertTrue($this->mapper->markConsumed($stored, 1050));
			$stored = $this->mapper->findByDeviceCode($deviceCode);
			$this->assertNotNull($stored);
			$this->assertSame(DeviceCode::STATUS_CONSUMED, $stored->getStatus());
			$this->assertSame(1050, $stored->getConsumedAt());
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
