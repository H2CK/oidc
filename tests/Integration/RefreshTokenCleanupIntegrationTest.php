<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Integration;

use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\RefreshTokenMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Server;

#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class RefreshTokenCleanupIntegrationTest extends \Test\TestCase {
    private AccessTokenMapper $accessTokens;
    private RefreshTokenMapper $refreshTokens;
    private array $created = [];

    protected function setUp(): void {
        parent::setUp();
        $this->accessTokens = Server::get(AccessTokenMapper::class);
        $this->refreshTokens = Server::get(RefreshTokenMapper::class);
    }

    protected function tearDown(): void {
        foreach ($this->created as $token) {
            $this->accessTokens->delete($token);
        }
        parent::tearDown();
    }

    private function token(int $refreshed): AccessToken {
        $token = new AccessToken();
        $token->setClientId(987654);
        $token->setUserId('oidc-cleanup-fixture');
        $token->setScope('openid offline_access');
        $token->setHashedCode(hash('sha512', bin2hex(random_bytes(16))));
        $token->setAccessToken(bin2hex(random_bytes(16)));
        $token->setCreated($refreshed);
        $token->setRefreshed($refreshed);
        $token->setExpiresAt($refreshed + 900);
        $token->setNonce('');
        $token = $this->accessTokens->insert($token);
        $this->created[] = $token;
        return $token;
    }

    public function testUsedRefreshRowsArePurgedEvenWhenGrantNeverExpires(): void {
        $now = time();
        $token = $this->token($now);
        $old = bin2hex(random_bytes(16));
        $recent = bin2hex(random_bytes(16));
        $unused = bin2hex(random_bytes(16));
        $oldRow = $this->refreshTokens->createForAccessToken($token->getId(), $old, $now - RefreshTokenMapper::USED_RETENTION - 100);
        $recentRow = $this->refreshTokens->createForAccessToken($token->getId(), $recent, $now - 100);
        $this->refreshTokens->createForAccessToken($token->getId(), $unused, $now - RefreshTokenMapper::USED_RETENTION - 100);
        $this->refreshTokens->markUsed($oldRow, $now - RefreshTokenMapper::USED_RETENTION - 1);
        $this->refreshTokens->markUsed($recentRow, $now - 1);
        $this->refreshTokens->cleanUp($now);
        $this->assertNull($this->refreshTokens->findByToken($old));
        $this->assertNotNull($this->refreshTokens->findByToken($recent));
        $this->assertNotNull($this->refreshTokens->findByToken($unused));
    }

    public function testExpiredAccessTokenCleanupExplicitlyDeletesRefreshRows(): void {
        $now = time();
        $token = $this->token($now - 10000);
        $secret = bin2hex(random_bytes(16));
        $this->refreshTokens->createForAccessToken($token->getId(), $secret, $now - 10000);
        $clock = $this->createMock(ITimeFactory::class);
        $clock->method('getTime')->willReturn($now);
        $config = $this->createMock(IAppConfig::class);
        $config->method('getAppValueString')->willReturnCallback(static fn (string $key): string => $key === 'refresh_expire_time' ? '7200' : '900');
        $mapper = new AccessTokenMapper(Server::get(\OCP\IDBConnection::class), $clock, $config);
        $mapper->cleanUp();
        $this->assertNull($this->refreshTokens->findByToken($secret));
    }

    public function testSqliteOrphanCleanupWithoutForeignKeyEnforcement(): void {
        $db = Server::get(\OCP\IDBConnection::class);
        if ($db->getDatabaseProvider() !== \OCP\IDBConnection::PLATFORM_SQLITE) {
            $this->markTestSkipped('SQLite-specific foreign-key-disabled regression.');
        }
        $result = $db->executeQuery('PRAGMA foreign_keys');
        try {
            $enabled = (int)$result->fetchOne();
        } finally {
            $result->closeCursor();
        }
        if ($enabled !== 0) {
            $this->markTestSkipped('Run this case in a SQLite test installation with foreign keys disabled.');
        }
        $secret = bin2hex(random_bytes(16));
        $this->refreshTokens->createForAccessToken(2147483647, $secret, time());
        try {
            $this->refreshTokens->cleanUp(time());
            $this->assertNull($this->refreshTokens->findByToken($secret));
        } finally {
            $row = $this->refreshTokens->findByToken($secret);
            if ($row !== null) { $this->refreshTokens->delete($row); }
        }
    }
}
