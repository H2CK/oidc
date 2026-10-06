<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Integration;

use OCA\OIDCIdentityProvider\Db\OperationLock;
use OCA\OIDCIdentityProvider\Service\AuthorizationTransactionService;
use OCA\OIDCIdentityProvider\Migration\InitializeOperationLocks;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
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

    public function testInstallRepairRestoresMissingAuthorizationLock(): void {
        $db = Server::get(IDBConnection::class);
        $db->beginTransaction();
        try {
            $qb = $db->getQueryBuilder();
            $qb->delete('oidc_operation_locks')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter(OperationLock::AUTHORIZATION)))
                ->executeStatement();

            (new InitializeOperationLocks($db))->run($this->createMock(IOutput::class));
            $qb = $db->getQueryBuilder();
            $qb->select('id')->from('oidc_operation_locks')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter(OperationLock::AUTHORIZATION)));
            $result = $qb->executeQuery();
            try {
                $this->assertSame(OperationLock::AUTHORIZATION, (int)$result->fetchOne());
            } finally {
                $result->closeCursor();
            }
            OperationLock::acquire($db, OperationLock::AUTHORIZATION);
        } finally {
            $db->rollBack();
        }
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
        $consumed = $otherNode->consume($id);
        $this->assertSame($parameters, $consumed['parameters']);
        $this->assertSame('prompt_login', $consumed['reason']);
        $this->assertIsInt($consumed['created_at']);
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

    public function testPostContinuationIsReasonBoundAndSingleUse(): void {
        $request = ['client_id' => 'client', 'scope' => 'openid Files:Read', 'state' => '0'];
        $id = $this->transactions->create($request, 'authorization_post');
        $this->assertNull($this->transactions->consume($id, 'prompt_login'));
        $this->assertTrue($this->transactions->isPending($id));
        $consumed = $this->transactions->consume($id, 'authorization_post');
        $this->assertSame($request, $consumed['parameters']);
        $this->assertSame('authorization_post', $consumed['reason']);
        $this->assertIsInt($consumed['created_at']);
        $this->assertNull($this->transactions->consume($id, 'authorization_post'));
    }
}
