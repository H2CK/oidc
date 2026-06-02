<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\BackgroundJob;

use PHPUnit\Framework\TestCase;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

use OCA\OIDCIdentityProvider\Db\GroupMapper;

use OCA\OIDCIdentityProvider\BackgroundJob\CleanupGroups;

use OCP\BackgroundJob\IJobList;

/**
 * Basic unit test to ensure a BackgroundJob can be constructed via DI
 * and that invoking run() in normal operation does not throw.
 */
class CleanupGroupsTest extends TestCase
{
	protected $job;
	/** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    private $time;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IConfig */
    private $config;
    /** @var \PHPUnit\Framework\MockObject\MockObject|GroupMapper */
    private $groupMapper;

    public function setUp(): void
    {
        $this->config = $this->createMock(IConfig::class);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->groupMapper = $this->createMock(GroupMapper::class);

		$this->job = new CleanupGroups(
            $this->time,
            $this->groupMapper,
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

        $this->assertEquals(24 * 60 * 60, $interval);
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
