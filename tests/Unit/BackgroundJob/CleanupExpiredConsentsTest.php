<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\BackgroundJob;

use PHPUnit\Framework\TestCase;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;

use OCA\OIDCIdentityProvider\Db\UserConsentMapper;

use OCA\OIDCIdentityProvider\BackgroundJob\CleanupExpiredConsents;

use OCP\BackgroundJob\IJobList;

use ReflectionClass;
use ReflectionException;

/**
 * Basic unit test to ensure a BackgroundJob can be constructed via DI
 * and that invoking run() in normal operation does not throw.
 */
class CleanupExpiredConsentsTest extends TestCase
{
    /** @var CleanupExpiredConsents */
    protected $job;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    private $time;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IConfig */
    private $config;
    /** @var \PHPUnit\Framework\MockObject\MockObject|UserConsentMapper */
    private $userConsentMapper;

    public function setUp(): void
    {
        $this->config = $this->createMock(IConfig::class);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->userConsentMapper = $this->createMock(UserConsentMapper::class);

        $this->job = new CleanupExpiredConsents(
            $this->time,
            $this->userConsentMapper,
            $this->config
        );
    }

    public function testJobRunsSuccessfully() {
        $jobList = $this->createMock(IJobList::class);
        $this->job->start($jobList);

        $this->assertTrue(true);
    }

    public function testConstructorSetsInterval() {
        $jobList = $this->createMock(IJobList::class);
        $this->job->start($jobList);

        $reflection = new ReflectionClass($this->job);
        $property = $reflection->getProperty('interval');
        $property->setAccessible(true);
        $interval = $property->getValue($this->job);

        $this->assertEquals(6 * 60 * 60, $interval);
    }

    public function testConstructorSetsTimeSensitivity() {
        $jobList = $this->createMock(IJobList::class);
        $this->job->start($jobList);

        $reflection = new ReflectionClass($this->job);
        $property = $reflection->getProperty('timeSensitivity');
        $property->setAccessible(true);
        $timeSensitivity = $property->getValue($this->job);

        $this->assertEquals(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE, $timeSensitivity);
    }
}
