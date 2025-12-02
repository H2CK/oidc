<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use OCA\OIDCIdentityProvider\Service\CustomClaimService;
use OCA\OIDCIdentityProvider\Db\CustomClaim;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\Accounts\IAccountManager;
use OCP\IUser;
use OCP\IDBConnection;
use OCP\Server;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OC\AppFramework\Utility\TimeFactory;
use OCP\Security\ISecureRandom;
use OC\Security\SecureRandom;

use Psr\Log\LoggerInterface;

class CustomClaimServiceTest extends TestCase {
        protected $service;

        /** @var \PHPUnit\Framework\MockObject\MockObject|CustomClaimMapper  */
        private $customClaimMapper;
        /** @var \PHPUnit\Framework\MockObject\MockObject|IUserManager */
        private $userManager;
        /** @var \PHPUnit\Framework\MockObject\MockObject|IGroupManager */
        private $groupManager;
        /** @var \PHPUnit\Framework\MockObject\MockObject|IAccountManager */
        private $accountManager;
        /** @var LoggerInterface */
        private $logger;
        /** @var IDBConnection */
        private $db;
        /** @var IAppConfig */
        private $appConfig;
        /** @var ISecureRandom */
        private $secureRandom;
        /** @var ITimeFactory */
        private $time;
        /** @var RedirectUriMapper  */
        private $redirectUriMapper;
        /** @var ClientMapper  */
        private $clientMapper;

    public function setUp(): void {
        $this->db = $this->getMockBuilder(IDBConnection::class)->getMock();
        $this->time = Server::get(TimeFactory::class);
        $this->appConfig = $this->getMockBuilder(IAppConfig::class)->getMock();
        $this->secureRandom = Server::get(SecureRandom::class);
        $this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->redirectUriMapper = $this->getMockBuilder(RedirectUriMapper::class)->setConstructorArgs([
            $this->db,
            $this->time,
            $this->appConfig])->getMock();
        $this->clientMapper = $this->getMockBuilder(ClientMapper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->customClaimMapper = $this->getMockBuilder(CustomClaimMapper::class)->setConstructorArgs([
            $this->db,
            $this->logger
            ])->getMock();
        $this->userManager = $this->getMockBuilder(IUserManager::class)->getMock();
        $this->groupManager = $this->getMockBuilder(IGroupManager::class)->getMock();
        $this->accountManager = $this->getMockBuilder(IAccountManager::class)->getMock();
        $this->service = new CustomClaimService(
            $this->customClaimMapper,
            $this->userManager,
            $this->groupManager,
            $this->accountManager,
            $this->logger
        );
    }

    public static function customClaims(): array
    {
        return [
            'Case 1-1' => [123, 'is_admin', 'profile', 'isAdmin', null, 'openid roles profile', true],
            'Case 2-1' => [123, 'has_role_user', 'profile', 'hasRole', 'User', 'openid roles profile', true],
        ];
    }

    #[DataProvider('customClaims')]
    public function testProvideCustomClaim(int $clientId, string $claim_name, string $scope, string $function, ?string $parameter, string $requestedScope, $expected_result): void {
        $this->customClaimMapper
            ->method('findByClient')
            ->willReturnCallback(
                function ($arg1) use ($scope, $claim_name, $function, $parameter) {
                    $new_custom_claim = new CustomClaim();
                    $new_custom_claim->setClientId($arg1);
                    $new_custom_claim->setScope($scope);
                    $new_custom_claim->setName($claim_name);
                    $new_custom_claim->setFunction($function);
                    $new_custom_claim->setParameter($parameter);
                    return [ $new_custom_claim ];
                }
            );

        $this->userManager
            ->method('get')
            ->willReturnCallback(
                function () {
                    $mockUser = $this->getMockBuilder(IUser::class)->getMock();
                    $mockUser->method('getUID')->willReturn('testuser');
                    return $mockUser;
                }
            );

        $this->groupManager
            ->method('isAdmin')
            ->willReturnCallback(
                function () use ($expected_result) {
                    return $expected_result;
                }
            );

        $this->groupManager
            ->method('isInGroup')
            ->willReturnCallback(
                function () use ($expected_result) {
                    return $expected_result;
                }
            );

        $result = $this->service->provideCustomClaims($clientId, $requestedScope, "12345");
        $this->assertIsArray($result, 'Result is not an array');
        $this->assertCount(1, $result, 'Result array does not contain exactly one claim');
        $this->assertEquals($claim_name, array_key_first($result), 'Claim name does not match');
        $this->assertEquals($expected_result, $result[$claim_name], 'Claim value does not match');
    }

}
