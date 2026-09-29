<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 OIDC Identity Provider contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\Group;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Service\ClientAuthorizationService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

class ClientAuthorizationServiceTest extends TestCase {
	private GroupMapper $groupMapper;
	private IGroupManager $groupManager;
	private ClientAuthorizationService $service;

	protected function setUp(): void {
		$this->groupMapper = $this->createMock(GroupMapper::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->service = new ClientAuthorizationService($this->groupMapper, $this->groupManager);
	}

	public function testClientWithoutRequiredGroupsAllowsUser(): void {
		$user = $this->createMock(IUser::class);
		$client = $this->createClient();
		$this->groupMapper->expects(self::once())->method('getGroupsByClientId')->with(23)->willReturn([]);
		$this->groupManager->expects(self::never())->method('getUserGroups');

		self::assertTrue($this->service->isUserAllowedForClient($user, $client));
	}

	public function testMembershipInAnyConfiguredGroupAllowsUser(): void {
		$user = $this->createMock(IUser::class);
		$client = $this->createClient();
		$this->groupMapper->method('getGroupsByClientId')->with(23)->willReturn([
			$this->createClientGroup('iot-users'),
			$this->createClientGroup('admins'),
		]);
		$this->groupManager->method('getUserGroups')->with($user)->willReturn([
			$this->createUserGroup('users'),
			$this->createUserGroup('iot-users'),
		]);

		self::assertTrue($this->service->isUserAllowedForClient($user, $client));
	}

	public function testUserWithoutMatchingGroupIsDenied(): void {
		$user = $this->createMock(IUser::class);
		$client = $this->createClient();
		$this->groupMapper->method('getGroupsByClientId')->willReturn([
			$this->createClientGroup('iot-users'),
			$this->createClientGroup('admins'),
		]);
		$this->groupManager->method('getUserGroups')->with($user)->willReturn([
			$this->createUserGroup('users'),
		]);

		self::assertFalse($this->service->isUserAllowedForClient($user, $client));
	}

	public function testMatchingGroupCanAppearAfterOtherUserGroups(): void {
		$user = $this->createMock(IUser::class);
		$client = $this->createClient();
		$this->groupMapper->method('getGroupsByClientId')->willReturn([$this->createClientGroup('group-a')]);
		$this->groupManager->method('getUserGroups')->with($user)->willReturn([
			$this->createUserGroup('group-x'),
			$this->createUserGroup('group-y'),
			$this->createUserGroup('group-a'),
		]);

		self::assertTrue($this->service->isUserAllowedForClient($user, $client));
	}

	private function createClient(): Client {
		$client = new Client();
		$client->setId(23);
		return $client;
	}

	private function createClientGroup(string $groupId): Group {
		$group = new Group();
		$group->setGroupId($groupId);
		return $group;
	}

	private function createUserGroup(string $groupId): IGroup {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($groupId);
		return $group;
	}
}
