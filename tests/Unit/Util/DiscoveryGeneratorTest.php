<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Services\IAppConfig;

use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Util\DiscoveryGenerator;

use Psr\Log\LoggerInterface;

class DiscoveryGeneratorTest extends TestCase {
    
    /** @var DiscoveryGenerator */
    protected $generator;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    private $time;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IURLGenerator */
    private $urlGenerator;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
    private $appConfig;
    
    /** @var LoggerInterface */
    private $logger;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|ClientMapper */
    private $clientMapper;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IRequest */
    private $request;

    public function setUp(): void {
        $this->time = $this->createMock(ITimeFactory::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->request = $this->createMock(IRequest::class);
        
        $this->urlGenerator->method('getWebroot')->willReturn('/');
        $this->urlGenerator->method('linkToRoute')
            ->willReturnCallback(function($routeName, $params) {
                return '/oidc/' . str_replace('.', '/', $routeName);
            });
        
        $this->request->method('getServerProtocol')->willReturn('https');
        $this->request->method('getServerHost')->willReturn('localhost');
        
        // Don't set default expectations here - let each test configure as needed
        // $this->appConfig->method('getAppValueString')
        //     ->willReturnCallback(function($key, $default) {
        //         return $default;
        //     });
        
        $this->generator = new DiscoveryGenerator(
            $this->time,
            $this->urlGenerator,
            $this->appConfig,
            $this->logger,
            $this->clientMapper
        );
    }

    public function testGenerateDiscoveryReturnsJsonResponse() {
        $result = $this->generator->generateDiscovery($this->request);
        
        $this->assertInstanceOf(JSONResponse::class, $result);
    }

    public function testGenerateDiscoveryHasCorrectStatus() {
        $result = $this->generator->generateDiscovery($this->request);
        
        $this->assertEquals(200, $result->getStatus());
    }

    public function testGenerateDiscoveryHasCorsHeaders() {
        $result = $this->generator->generateDiscovery($this->request);
        
        $headers = $result->getHeaders();
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
        $this->assertArrayHasKey('Access-Control-Allow-Methods', $headers);
        $this->assertEquals('GET', $headers['Access-Control-Allow-Methods']);
    }

    public function testGenerateDiscoveryHasIssuer() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayHasKey('issuer', $data);
        $this->assertEquals('https://localhost/', $data['issuer']);
    }

    public function testGenerateDiscoveryHasRequiredEndpoints() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        // Required endpoints
        $requiredEndpoints = [
            'authorization_endpoint',
            'token_endpoint',
            'userinfo_endpoint',
            'jwks_uri',
            'end_session_endpoint'
        ];
        
        foreach ($requiredEndpoints as $endpoint) {
            $this->assertArrayHasKey($endpoint, $data, "Missing endpoint: $endpoint");
            $this->assertStringContainsString('/oidc/', $data[$endpoint]);
        }
    }

    public function testGenerateDiscoveryHasScopesSupported() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayHasKey('scopes_supported', $data);
        $scopes = $data['scopes_supported'];
        
        // Default scopes should be present
        $expectedScopes = ['openid', 'profile', 'email', 'roles', 'groups', 'offline_access'];
        foreach ($expectedScopes as $scope) {
            $this->assertContains($scope, $scopes, "Missing scope: $scope");
        }
    }

    public function testGenerateDiscoveryHasResponseTypesSupported() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayHasKey('response_types_supported', $data);
        $responseTypes = $data['response_types_supported'];
        
        $expectedTypes = ['code', 'code id_token', 'id_token'];
        foreach ($expectedTypes as $type) {
            $this->assertContains($type, $responseTypes, "Missing response type: $type");
        }
    }

    public function testGenerateDiscoveryHasGrantTypesSupported() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayHasKey('grant_types_supported', $data);
        $grantTypes = $data['grant_types_supported'];
        
        $expectedTypes = ['authorization_code', 'implicit'];
        foreach ($expectedTypes as $type) {
            $this->assertContains($type, $grantTypes, "Missing grant type: $type");
        }
    }

    public function testGenerateDiscoveryHasIdTokenSigningAlgValues() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayHasKey('id_token_signing_alg_values_supported', $data);
        $algs = $data['id_token_signing_alg_values_supported'];
        
        $this->assertContains('RS256', $algs);
        $this->assertContains('HS256', $algs);
    }

    public function testGenerateDiscoveryHasTokenEndpointAuthMethods() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayHasKey('token_endpoint_auth_methods_supported', $data);
        $methods = $data['token_endpoint_auth_methods_supported'];
        
        $this->assertContains('client_secret_post', $methods);
        $this->assertContains('client_secret_basic', $methods);
    }

    public function testGenerateDiscoveryHasClaimsSupported() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayHasKey('claims_supported', $data);
        $claims = $data['claims_supported'];
        
        // Required claims
        $requiredClaims = ['iss', 'sub', 'aud', 'exp', 'iat'];
        foreach ($requiredClaims as $claim) {
            $this->assertContains($claim, $claims, "Missing claim: $claim");
        }
    }

    public function testGenerateDiscoveryHasPkceSupport() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayHasKey('code_challenge_methods_supported', $data);
        $methods = $data['code_challenge_methods_supported'];
        
        $this->assertContains('S256', $methods);
        $this->assertContains('plain', $methods);
    }

    public function testGenerateDiscoveryHasIntrospectionEndpoint() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayHasKey('introspection_endpoint', $data);
        $this->assertArrayHasKey('introspection_endpoint_auth_methods_supported', $data);
        
        $authMethods = $data['introspection_endpoint_auth_methods_supported'];
        $this->assertContains('client_secret_post', $authMethods);
        $this->assertContains('client_secret_basic', $authMethods);
    }

    public function testGenerateDiscoveryWithDynamicClientRegistration() {
        // Mock getAppValueString to return 'true' for dynamic_client_registration
        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function($key, $default) {
                if ($key === 'dynamic_client_registration') {
                    return 'true';
                }
                return $default;
            });
        
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayHasKey('registration_endpoint', $data);
        $this->assertStringContainsString('/oidc/DynamicRegistration/registerClient', $data['registration_endpoint']);
    }

    public function testGenerateDiscoveryWithoutDynamicClientRegistration() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayNotHasKey('registration_endpoint', $data);
    }

    public function testGetAggregatedScopesWithClients() {
        // Create real Client entities
        $client1 = new Client();
        $client1->setAllowedScopes('profile email');
        
        $client2 = new Client();
        $client2->setAllowedScopes('email roles groups');
        
        $client3 = new Client();
        $client3->setAllowedScopes(''); // Empty scopes
        
        $this->clientMapper->method('getClients')
            ->willReturn([$client1, $client2, $client3]);
        
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $scopes = $data['scopes_supported'];
        
        // Should have default scopes plus custom scopes
        $this->assertContains('profile', $scopes);
        $this->assertContains('email', $scopes);
        $this->assertContains('roles', $scopes);
        $this->assertContains('groups', $scopes);
        
        // Should not have duplicates
        $this->assertEquals(count(array_unique($scopes)), count($scopes), 'Duplicate scopes found');
    }

    public function testGetAggregatedScopesWithException() {
        $this->clientMapper->method('getClients')
            ->willThrowException(new \Exception('Database error'));
        
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Failed to aggregate scopes from OAuth clients: Database error');
        
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        // Should still have default scopes
        $scopes = $data['scopes_supported'];
        $this->assertContains('openid', $scopes);
        $this->assertContains('profile', $scopes);
        $this->assertContains('email', $scopes);
    }

    public function testGetAggregatedScopesWithDuplicateScopes() {
        // Create real Client entities
        $client1 = new Client();
        $client1->setAllowedScopes('profile email profile');
        
        $client2 = new Client();
        $client2->setAllowedScopes('EMAIL PROFILE');
        
        $this->clientMapper->method('getClients')
            ->willReturn([$client1, $client2]);
        
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $scopes = $data['scopes_supported'];
        
        // Should deduplicate case-insensitive
        $this->assertEquals(1, count(array_filter($scopes, function($s) { return strtolower($s) === 'profile'; })));
        $this->assertEquals(1, count(array_filter($scopes, function($s) { return strtolower($s) === 'email'; })));
    }

    public function testGenerateDiscoveryHasSubjectTypesSupported() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayHasKey('subject_types_supported', $data);
        $this->assertContains('public', $data['subject_types_supported']);
    }

    public function testGenerateDiscoveryHasDisplayValuesSupported() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayHasKey('display_values_supported', $data);
        $this->assertContains('page', $data['display_values_supported']);
    }

    public function testGenerateDiscoveryHasClaimTypesSupported() {
        $result = $this->generator->generateDiscovery($this->request);
        $data = $result->getData();
        
        $this->assertArrayHasKey('claim_types_supported', $data);
        $this->assertContains('normal', $data['claim_types_supported']);
    }

    public function testGenerateDiscoveryLogsInfo() {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Request to Discovery Endpoint.');
        
        $this->generator->generateDiscovery($this->request);
    }
}
