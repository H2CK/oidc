<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\BackgroundJob;

use PHPUnit\Framework\TestCase;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\RegistrationTokenMapper;

use OCA\OIDCIdentityProvider\BackgroundJob\CleanupExpiredTokens;

use OCP\BackgroundJob\IJobList;

/**
 * Basic unit test to ensure a BackgroundJob can be constructed via DI
 * and that invoking run() in normal operation does not throw.
 */
class CleanupExpiredTokensTest extends TestCase
{
	protected $job;
	/** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    private $time;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IConfig */
    private $config;
    /** @var \PHPUnit\Framework\MockObject\MockObject|AccessTokenMapper */
    private $accessTokenMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|RegistrationTokenMapper */
    private $registrationTokenMapper;

    public function setUp(): void
    {
        $this->config = $this->createMock(IConfig::class);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->accessTokenMapper = $this->createMock(AccessTokenMapper::class);
        $this->registrationTokenMapper = $this->createMock(RegistrationTokenMapper::class);

		$this->job = new CleanupExpiredTokens(
            $this->time,
            $this->accessTokenMapper,
            $this->registrationTokenMapper,
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

        $reflection = new \ReflectionClass($this->job);
        $property = $reflection->getProperty('interval');
        $property->setAccessible(true);
        $interval = $property->getValue($this->job);

        $this->assertEquals(6 * 60 * 60, $interval);
    }

    public function testConstructorSetsTimeSensitivity() {
        $jobList = $this->createMock(IJobList::class);
        $this->job->start($jobList);

        $reflection = new \ReflectionClass($this->job);
        $property = $reflection->getProperty('timeSensitivity');
        $property->setAccessible(true);
        $timeSensitivity = $property->getValue($this->job);

        $this->assertEquals(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE, $timeSensitivity);
    }
}
