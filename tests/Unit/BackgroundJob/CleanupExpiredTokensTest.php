<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\BackgroundJob;

use PHPUnit\Framework\TestCase;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\AuthorizationCodeMapper;
use OCA\OIDCIdentityProvider\Db\RegistrationTokenMapper;

use OCA\OIDCIdentityProvider\BackgroundJob\CleanupExpiredTokens;

use OCP\BackgroundJob\IJobList;

use ReflectionClass;
use ReflectionException;

/**
 * Basic unit test to ensure a BackgroundJob can be constructed via DI
 * and that invoking run() in normal operation does not throw.
 */
class CleanupExpiredTokensTest extends TestCase
{
    /** @var CleanupExpiredTokens */
    protected $job;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    private $time;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
    private $appConfig;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IConfig */
    private $config;
    /** @var \PHPUnit\Framework\MockObject\MockObject|AccessTokenMapper */
    private $accessTokenMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|AuthorizationCodeMapper */
    private $authorizationCodeMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|RegistrationTokenMapper */
    private $registrationTokenMapper;

    public function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig
            ->method('getAppValueString')
            ->willReturnCallback(function ($key, $default = '') {
                if ($key === Application::APP_CONFIG_DEFAULT_EXPIRE_TIME) {
                    return '3600';
                }
                if ($key === Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME) {
                    return '7200';
                }
                return $default;
            });
        $this->config = $this->createMock(IConfig::class);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->time
            ->method('getTime')
            ->willReturn(1234567890);
        $this->accessTokenMapper = $this->createMock(AccessTokenMapper::class);
        $this->authorizationCodeMapper = $this->createMock(AuthorizationCodeMapper::class);
        $this->registrationTokenMapper = $this->createMock(RegistrationTokenMapper::class);

        $this->job = new CleanupExpiredTokens(
            $this->time,
            $this->accessTokenMapper,
            $this->authorizationCodeMapper,
            $this->registrationTokenMapper,
            $this->appConfig,
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
