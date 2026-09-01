<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\BackgroundJob;

use OCA\OIDCIdentityProvider\BackgroundJob\BackChannelLogoutRetryJob;
use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class BackChannelLogoutRetryJobTest extends TestCase {
    public function testQueuedJobDelegatesValidArgumentToService(): void {
        $argument = [
            'client_db_id' => 7,
            'sid' => 'sid-1',
            'attempt' => 1,
        ];
        $service = $this->createMock(BackChannelLogoutService::class);
        $service->expects($this->once())->method('retry')->with($argument);

        $job = new class($this->createMock(ITimeFactory::class), $service) extends BackChannelLogoutRetryJob {
            public function runForTest(mixed $argument): void {
                $this->run($argument);
            }
        };

        $job->runForTest($argument);
    }

    public function testQueuedJobIgnoresNonArrayArgument(): void {
        $service = $this->createMock(BackChannelLogoutService::class);
        $service->expects($this->never())->method('retry');

        $job = new class($this->createMock(ITimeFactory::class), $service) extends BackChannelLogoutRetryJob {
            public function runForTest(mixed $argument): void {
                $this->run($argument);
            }
        };

        $job->runForTest('invalid');
    }
}
