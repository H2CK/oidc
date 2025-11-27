<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use OCP\IRequest;
use OCP\IL10N;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IConfig;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Http;
use OCP\Security\ISecureRandom;

use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Controller\CustomClaimController;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\CustomClaim;
use Psr\Log\LoggerInterface;

class CustomClaimControllerTest extends TestCase {
    protected $controller;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IRequest */
    protected $request;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ClientMapper */
    private $clientMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|CustomClaimMapper */
    private $customClaimMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|RedirectUriMapper  */
    private $redirectUriMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IL10N */
    private $l;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IUserSession */
    private $userSession;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
    private $appConfig;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IConfig */
    private $config;
    /** @var LoggerInterface */
    private $logger;
    /** @var ISecureRandom */
    private $secureRandom;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    private $time;
    /** @var IDBConnection */
    private $db;

    private $client;

    public function setUp(): void {
        $this->request = $this->getMockBuilder(IRequest::class)->getMock();
        $this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->appConfig = $this->getMockBuilder(IAppConfig::class)->getMock();
        $this->config = $this->getMockBuilder(IConfig::class)->getMock();
        $this->userSession = $this->getMockBuilder(IUserSession::class)->getMock();
        $this->secureRandom = $this->getMockBuilder(ISecureRandom::class)->getMock();
        $this->time = $this->getMockBuilder(ITimeFactory::class)->getMock();
        $this->db = $this->getMockBuilder(IDBConnection::class)->getMock();
        $this->l = $this->getMockBuilder(IL10N::class)->getMock();
        $this->redirectUriMapper = $this->getMockBuilder(RedirectUriMapper::class)->setConstructorArgs([
            $this->db,
            $this->time,
            $this->appConfig])->getMock();
        $this->customClaimMapper = $this->getMockBuilder(CustomClaimMapper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->clientMapper = $this->getMockBuilder(ClientMapper::class)->setConstructorArgs([
            $this->db,
            $this->time,
            $this->appConfig,
            $this->redirectUriMapper,
            $this->customClaimMapper,
            $this->secureRandom,
            $this->logger])->getMock();

        $this->controller = new CustomClaimController(
            'oidc',
            $this->request,
            $this->clientMapper,
            $this->customClaimMapper,
            $this->l,
            $this->userSession,
            $this->appConfig,
            $this->config,
            $this->logger
        );
    }

    public static function customClaimsAdd(): array
    {
        return [
            'Case 1-1' => [123, null, 'is_admin', 'openid', 'isAdmin', null, true],
            'Case 2-1' => [123, null, 'has_role_user', 'openid', 'hasRole', 'User', true],
            'Case 3-1' => [null, 'adfklkoodessgsg', 'has_role_user', 'openid', 'hasRole', 'User', true],
            'Case 4-1' => [null, null, 'has_role_user', 'openid', 'hasRole', 'User', false],
            'Case 5-1' => [123, 'AAA', 'has_role_user', 'openid', 'hasRole', 'User', true],
        ];
    }

    #[DataProvider('customClaimsAdd')]
    public function testAddClient(
            ?int $clientUid,
            ?string $clientId,
            ?string $claim_name,
            ?string $scope,
            ?string $function,
            ?string $parameter,
            $expected_result)
    {
        $cid = 1235;
        $client = new Client();
        $client->setId($clientUid ?? $cid);
        $client->setName('TEST');
        $client->setSigningAlg('RS256');
        $client->setClientIdentifier($clientId ?? 'test-client-identifier');
        $client->setSecret('0582bb51ac974f318c4fe11779c439a0');
        $client->setType('confidential');
        $client->setFlowType('code');
        $client->setTokenType('opaque');
        $client->setRedirectUris(['https://local.lo']);

        $this->clientMapper
            ->method('getByUid')
            ->willReturnCallBack (
                function ($arg) use ($client) {
                    $client->setId($arg);
                    return $client;
                }
            );

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturnCallBack (
                function ($arg) use ($client) {
                    $client->setClientIdentifier($arg);
                    return $client;
                }
            );

        $this->customClaimMapper
            ->method('createOrUpdate')
            ->willReturnCallBack (
                function ($arg) use ($cid){
                    $arg->setId($cid);
                    return $arg;
                }
            );

        $result = $this->controller->addCustomClaim(
            $claim_name,
            $scope,
            $function,
            $parameter,
            $clientId,
            $clientUid
        );

        if ($expected_result) {
            $this->assertEquals(Http::STATUS_OK, $result->getStatus(), 'Status Code does not match!');
            $this->assertEquals($cid, $result->getData()['id']);
            $this->assertEquals($client->getId(), $result->getData()['clientId']);
            $this->assertEquals($client->getClientIdentifier(), $result->getData()['clientIdentifier']);
            $this->assertEquals($claim_name, $result->getData()['name']);
            $this->assertEquals($scope, $result->getData()['scope']);
            $this->assertEquals($function, $result->getData()['function']);
            $this->assertEquals($parameter, $result->getData()['parameter']);
        } else {
            $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus(), 'Status Code does not match!');
        }
    }

    public static function customClaimsUpdate(): array
    {
        return [
            'Case 1-1' => [null, 123, null, 'is_admin', 'openid', 'isAdmin', null, true],
            'Case 2-1' => [null, 123, null, 'has_role_user', 'openid', 'hasRole', 'User', true],
            'Case 3-1' => [null, null, 'adfklkoodessgsg', 'has_role_user', 'openid', 'hasRole', 'User', true],
            'Case 4-1' => [null, null, null, 'has_role_user', 'openid', 'hasRole', 'User', false],
            'Case 5-1' => [null, 123, 'AAA', 'has_role_user', 'openid', 'hasRole', 'User', true],
            'Case 6-1' => [1235, 123, 'AAA', 'has_role_user', 'openid', 'hasRole', 'User', true],
            'Case 7-1' => [222, 123, 'AAA', 'has_role_user', 'openid', 'hasRole', 'User', true], // id is not relevant for processing
        ];
    }

    #[DataProvider('customClaimsUpdate')]
    public function testUpdateClient(
            ?int $id,
            ?int $clientUid,
            ?string $clientId,
            ?string $claim_name,
            ?string $scope,
            ?string $function,
            ?string $parameter,
            $expected_result)
    {
        $cid = 1235;
        $client = new Client();
        $client->setId($clientUid ?? $cid);
        $client->setName('TEST');
        $client->setSigningAlg('RS256');
        $client->setClientIdentifier($clientId ?? 'test-client-identifier');
        $client->setSecret('0582bb51ac974f318c4fe11779c439a0');
        $client->setType('confidential');
        $client->setFlowType('code');
        $client->setTokenType('opaque');
        $client->setRedirectUris(['https://local.lo']);

        $this->clientMapper
            ->method('getByUid')
            ->willReturnCallBack (
                function ($arg) use ($client) {
                    $client->setId($arg);
                    return $client;
                }
            );

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturnCallBack (
                function ($arg) use ($client) {
                    $client->setClientIdentifier($arg);
                    return $client;
                }
            );

        $this->customClaimMapper
            ->method('createOrUpdate')
            ->willReturnCallBack (
                function ($arg) use ($cid){
                    $arg->setId($cid);
                    return $arg;
                }
            );

        $result = $this->controller->updateCustomClaim(
            $claim_name,
            $scope,
            $function,
            $parameter,
            $clientId,
            $clientUid,
            $id
        );

        if ($expected_result) {
            $this->assertEquals(Http::STATUS_OK, $result->getStatus(), 'Status Code does not match!');
            $this->assertEquals($cid, $result->getData()['id']);
            $this->assertEquals($client->getId(), $result->getData()['clientId']);
            $this->assertEquals($client->getClientIdentifier(), $result->getData()['clientIdentifier']);
            $this->assertEquals($claim_name, $result->getData()['name']);
            $this->assertEquals($scope, $result->getData()['scope']);
            $this->assertEquals($function, $result->getData()['function']);
            $this->assertEquals($parameter, $result->getData()['parameter']);
        } else {
            $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus(), 'Status Code does not match!');
        }
    }

    public static function customClaimsDelete(): array
    {
        return [
            'Case 1-1' => [null, 123, null, 'is_admin', true],
            'Case 2-1' => [null, 123, null, '', false],
            'Case 2-2' => [222, 123, null, '', true],
            'Case 3-1' => [null, null, 'adfklkoodessgsg', 'has_role_user', true],
            'Case 4-1' => [null, null, null, 'has_role_user', false],
            'Case 5-1' => [null, 123, 'AAA', 'has_role_user', true],
            'Case 6-1' => [1235, 123, 'AAA', 'has_role_user', true],
            'Case 7-1' => [222, 123, 'AAA', 'has_role_user', true],
        ];
    }

    #[DataProvider('customClaimsDelete')]
    public function testDeleteClient(
            ?int $id,
            ?int $clientUid,
            ?string $clientId,
            ?string $claim_name,
            $expected_result)
    {
        $cid = 1235;
        $client = new Client();
        $client->setId($clientUid ?? $cid);
        $client->setName('TEST');
        $client->setSigningAlg('RS256');
        $client->setClientIdentifier($clientId ?? 'test-client-identifier');
        $client->setSecret('0582bb51ac974f318c4fe11779c439a0');
        $client->setType('confidential');
        $client->setFlowType('code');
        $client->setTokenType('opaque');
        $client->setRedirectUris(['https://local.lo']);

        $this->clientMapper
            ->method('getByUid')
            ->willReturnCallBack (
                function ($arg) use ($client) {
                    $client->setId($arg);
                    return $client;
                }
            );

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturnCallBack (
                function ($arg) use ($client) {
                    $client->setClientIdentifier($arg);
                    return $client;
                }
            );

        $this->customClaimMapper
            ->method('findById')
            ->willReturnCallBack (
                function (){
                    return new CustomClaim();
                }
            );

        $this->customClaimMapper
            ->method('deleteByClientAndName')
            ->willReturnCallBack (
                function (){
                    return;
                }
            );

        $result = $this->controller->deleteCustomClaim(
            $claim_name,
            $clientId,
            $clientUid,
            $id
        );

        if ($expected_result) {
            $this->assertEquals(Http::STATUS_OK, $result->getStatus(), 'Status Code does not match!');
        } else {
            $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus(), 'Status Code does not match!');
        }
    }

}
