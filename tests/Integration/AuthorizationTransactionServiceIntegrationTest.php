<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Integration;

use OCA\OIDCIdentityProvider\Service\AuthorizationTransactionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Server;

#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class AuthorizationTransactionServiceIntegrationTest extends \Test\TestCase {
    private int $now;
    private AuthorizationTransactionService $transactions;
    private ITimeFactory $clock;

    protected function setUp(): void {
        parent::setUp();
        $this->now = time();
        $this->clock = $this->createMock(ITimeFactory::class);
        $this->clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $this->transactions = new AuthorizationTransactionService(Server::get(IDBConnection::class), $this->clock);
    }

    protected function tearDown(): void {
        $this->now += AuthorizationTransactionService::TTL + 1;
        $this->transactions->cleanup();
        parent::tearDown();
    }

    public function testParametersSurviveAnotherServiceInstanceAndTokenCannotBeReplayed(): void {
        $parameters = [
            'client_id' => 'client-1', 'state' => '+ä&', 'response_type' => 'code',
            'redirect_uri' => 'https://rp.example/callback?a=1', 'scope' => 'openid profile',
            'nonce' => 'nonce', 'resource' => 'https://resource.example/path',
            'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
            'prompt' => 'login', 'max_age' => '0', 'response_mode' => 'form_post',
            'claims' => '{"userinfo":{"email":{"essential":true}}}',
        ];
        $id = $this->transactions->create($parameters, 'prompt_login');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $id);
        $otherNode = new AuthorizationTransactionService(Server::get(IDBConnection::class), $this->clock);
        $this->assertTrue($otherNode->isPending($id));
        $this->assertSame(['parameters' => $parameters, 'reason' => 'prompt_login'], $otherNode->consume($id));
        $this->assertFalse($this->transactions->isPending($id));
        $this->assertNull($this->transactions->consume($id));
        $this->assertFalse($this->transactions->isPending('not-a-valid-identifier'));
        $this->assertNull($this->transactions->consume('not-a-valid-identifier'));
    }

    public function testExpiredTransactionFailsAndIsDeletedByCleanup(): void {
        $id = $this->transactions->create(['client_id' => 'client-1'], 'not_authenticated');
        $this->assertTrue($this->transactions->isPending($id));
        $this->now += AuthorizationTransactionService::TTL;
        $this->assertFalse($this->transactions->isPending($id));
        $this->assertNull($this->transactions->consume($id));
        $this->now++;
        $this->transactions->cleanup();
        $this->assertNull($this->transactions->consume($id));
    }
}
