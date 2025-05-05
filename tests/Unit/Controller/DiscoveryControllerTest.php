<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

use OCP\AppFramework\Http;
use OCP\IRequest;
use OC\Security\Bruteforce\Throttler;
use OC\Security\Ip\BruteforceAllowList;
use OC\Security\Ip\Factory;
use OC\Security\Bruteforce\Backend\IBackend;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Services\IAppConfig;
use OCP\ILogger;

use OCA\OIDCIdentityProvider\Controller\DiscoveryController;
use OCA\OIDCIdentityProvider\Util\DiscoveryGenerator;

class DiscoveryControllerTest extends TestCase {
    protected $controller;
    /** @var IRequest */
    protected $request;
    /** @var ITimeFactory */
    private $time;
    /** @var Throttler */
    private $throttler;
    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var IDBConnection */
    private $db;
    /** @var IConfig */
    private $config;
    /** @var IAppConfig */
    private $appConfig;
    /** @var LoggerInterface */
    private $logger;
    /** @var IBackend */
    private $throttlerBackend;
    /** @var DiscoveryGenerator */
    private $discoveryGenerator;
    /** @var BruteforceAllowList */
    private $bruteforceAllowList;

    public function setUp(): void {
        $this->request = $this->getMockBuilder(IRequest::class)->getMock();
        $this->time = $this->getMockBuilder(ITimeFactory::class)->getMock();
        $this->db = $this->getMockBuilder(IDBConnection::class)->getMock();
        $this->urlGenerator = $this->getMockBuilder(IURLGenerator::class)->getMock();
        $this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->throttlerBackend = $this->getMockBuilder(IBackend::class)->getMock();
        $this->config = $this->getMockBuilder(IConfig::class)->getMock();
        $this->appConfig = $this->getMockBuilder(IAppConfig::class)->getMock();
        $this->bruteforceAllowList = new BruteforceAllowList($this->getMockBuilder(\OCP\IAppConfig::class)->getMock(), new Factory());
        $this->throttler = $this->getMockBuilder(Throttler::class)->setConstructorArgs([$this->time,
                                                                                        $this->logger,
                                                                                        $this->config,
                                                                                        $this->throttlerBackend,
                                                                                        $this->bruteforceAllowList])->getMock();
        $this->urlGenerator = $this->getMockBuilder(IURLGenerator::class)->getMock();
        $this->discoveryGenerator = new DiscoveryGenerator(
                                                            $this->time,
                                                            $this->urlGenerator,
                                                            $this->appConfig,
                                                            $this->logger
        );

        $this->controller = new DiscoveryController(
            'oidc',
            $this->request,
            $this->time,
            $this->throttler,
            $this->urlGenerator,
            $this->discoveryGenerator,
            $this->logger
        );
    }

    public function testDiscoveryResponse() {
        $issuer = $this->request->getServerProtocol() . '://' . $this->request->getServerHost() . $this->urlGenerator->getWebroot();
        $scopesSupported = [
            'openid',
            'profile',
            'email',
            'roles',
            'groups',
        ];
        $responseTypesSupported = [
            'code',
            'code id_token',
            // 'code token',
            // 'code id_token token',
            'id_token',
            // 'id_token token'
        ];
        $responseModesSupported = [
            'query',
            // 'fragment',
        ];
        $grantTypesSupported = [
            'authorization_code',
            'implicit',
        ];
        $acrValuesSupported = [
            '0',
        ];
        $subjectTypesSupported = [
            // 'pairwise',
            'public',
        ];
        $idTokenSigningAlgValuesSupported = [
            'RS256',
            'HS256',
        ];
        $userinfoSigningAlgValuesSupported = [
            'none',
        ];
        $tokenEndpointAuthMethodsSupported = [
            'client_secret_post',
            'client_secret_basic',
            // 'client_secret_jwt',
            // 'private_key_jwt',
        ];
        $displayValuesSupported = [
            'page',
            // 'popup',
            // 'touch',
            // 'wap',
        ];
        $claimTypesSupported = [
            'normal',
            // 'aggregated',
            // 'distributed',
        ];
        $claimsSupported = [
            'iss',
            'sub',
            'aud',
            'exp',
            'auth_time',
            'iat',
            'acr',
            'azp',
            'preferred_username',
            'scope',
            'nbf',
            'jti',
            'roles',
            'name',
            'updated_at',
            'website',
            'email',
            'email_verified',
            'phone_number',
            'address',
            'picture',
            'quota',
        ];

        $result = $this->controller->getInfo();

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $this->assertEquals($issuer, $result->getData()['issuer']);
        $this->assertEquals($scopesSupported, $result->getData()['scopes_supported']);
        $this->assertEquals($responseTypesSupported, $result->getData()['response_types_supported']);
        $this->assertEquals($responseModesSupported, $result->getData()['response_modes_supported']);
        $this->assertEquals($grantTypesSupported, $result->getData()['grant_types_supported']);
        $this->assertEquals($acrValuesSupported, $result->getData()['acr_values_supported']);
        $this->assertEquals($subjectTypesSupported, $result->getData()['subject_types_supported']);
        $this->assertEquals($idTokenSigningAlgValuesSupported, $result->getData()['id_token_signing_alg_values_supported']);
        $this->assertEquals($userinfoSigningAlgValuesSupported, $result->getData()['userinfo_signing_alg_values_supported']);
        $this->assertEquals($tokenEndpointAuthMethodsSupported, $result->getData()['token_endpoint_auth_methods_supported']);
        $this->assertEquals($displayValuesSupported, $result->getData()['display_values_supported']);
        $this->assertEquals($claimTypesSupported, $result->getData()['claim_types_supported']);
        $this->assertEquals($claimsSupported, $result->getData()['claims_supported']);
    }


}
