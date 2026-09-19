<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Db\GroupScope;
use OCA\OIDCIdentityProvider\Db\GroupScopeMapper;
use OCA\OIDCIdentityProvider\Service\ScopeCeilingService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScopeCeilingServiceTest extends TestCase {

    private GroupScopeMapper&MockObject $mapper;
    private IGroupManager&MockObject $groupManager;
    private IUserManager&MockObject $userManager;
    private LoggerInterface&MockObject $logger;
    private ScopeCeilingService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(GroupScopeMapper::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new ScopeCeilingService($this->mapper, $this->groupManager, $this->userManager, $this->logger);
    }

    /**
     * @param array<string, string> $rows group id => ceiling
     */
    private function givenUser(array $userGroups, array $rows): void {
        $this->userManager->method('get')->willReturn($this->createMock(IUser::class));
        $this->groupManager->method('getUserGroupIds')->willReturn($userGroups);
        $this->mapper->method('findByGroupIds')->willReturnCallback(function (array $gids) use ($rows) {
            $result = [];
            foreach ($gids as $gid) {
                if (isset($rows[$gid])) {
                    $row = new GroupScope();
                    $row->setGroupId($gid);
                    $row->setScopes($rows[$gid]);
                    $result[] = $row;
                }
            }
            return $result;
        });
    }

    public function testNoConfiguredGroupLeavesScopesUnchanged(): void {
        $this->givenUser(['users'], ['other' => 'notes.read']);
        $this->logger->expects($this->never())->method('info');

        $this->assertSame('openid notes.write', $this->service->clamp('alice', 'openid notes.write'));
    }

    public function testUnknownUserLeavesScopesUnchanged(): void {
        $this->userManager->method('get')->willReturn(null);

        $this->assertSame('openid notes.write', $this->service->clamp('ghost', 'openid notes.write'));
    }

    public function testSingleGroupCeiling(): void {
        $this->givenUser(['readers'], ['readers' => 'notes.read']);
        $this->logger->expects($this->once())->method('info');

        $this->assertSame(
            'openid profile email roles notes.read',
            $this->service->clamp('alice', 'openid profile email roles notes.read notes.write files.read', 'client-a'),
        );
    }

    public function testUnionOfGroups(): void {
        $this->givenUser(['readers', 'files'], ['readers' => 'notes.read', 'files' => 'files.read  files.write']);

        $this->assertSame(
            'openid notes.read files.read',
            $this->service->clamp('alice', 'openid notes.read notes.write files.read'),
        );
    }

    public function testDefaultScopesAlwaysAllowed(): void {
        $this->givenUser(['locked'], ['locked' => '']);

        $this->assertSame('openid profile email roles', $this->service->clamp('alice', 'openid profile email roles notes.read'));
    }

    public function testEverythingRemovedReturnsEmpty(): void {
        $this->givenUser(['readers'], ['readers' => 'notes.read']);

        $this->assertSame('', $this->service->clamp('alice', 'notes.write files.write'));
    }

    public function testMatchIsCaseInsensitive(): void {
        $this->givenUser(['readers'], ['readers' => 'Notes.Read']);

        $this->assertSame('openid notes.read', $this->service->clamp('alice', 'openid notes.read'));
    }
}
