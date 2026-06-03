<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

use OCP\IRequest;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http;

use OCA\OIDCIdentityProvider\Controller\CorsController;

use Psr\Log\LoggerInterface;

// Dummy Throttler class for testing purposes
if (!class_exists('\\OC\\Security\\Bruteforce\\Throttler')) {
    eval('namespace OC\Security\Bruteforce { class Throttler {} }');
}

class CorsControllerTest extends TestCase {
    
    /** @var CorsController */
    protected $controller;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IRequest */
    protected $request;
    
    /** @var \OC\Security\Bruteforce\Throttler|\PHPUnit\Framework\MockObject\MockObject */
    private $throttler;
    
    /** @var LoggerInterface */
    private $logger;

    public function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->throttler = $this->createMock('\\OC\\Security\\Bruteforce\\Throttler');
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->controller = new CorsController(
            'oidc',
            $this->request,
            $this->throttler,
            $this->logger
        );
    }

    public function testDiscoveryCorsResponse() {
        $result = $this->controller->discoveryCorsResponse();
        
        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        
        // Check CORS headers are set
        $headers = $result->getHeaders();
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
        $this->assertArrayHasKey('Access-Control-Allow-Methods', $headers);
        $this->assertEquals('PUT, POST, GET, DELETE, PATCH', $headers['Access-Control-Allow-Methods']);
        $this->assertArrayHasKey('Access-Control-Allow-Headers', $headers);
        $this->assertArrayHasKey('Access-Control-Max-Age', $headers);
        $this->assertEquals('1728000', $headers['Access-Control-Max-Age']);
    }

    public function testJwksCorsResponse() {
        $result = $this->controller->jwksCorsResponse();
        
        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        
        $headers = $result->getHeaders();
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testUserInfoCorsResponse() {
        $result = $this->controller->userInfoCorsResponse();
        
        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        
        $headers = $result->getHeaders();
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testLogoutCorsResponse() {
        $result = $this->controller->logoutCorsResponse();
        
        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        
        $headers = $result->getHeaders();
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testTokenCorsResponse() {
        $result = $this->controller->tokenCorsResponse();
        
        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        
        $headers = $result->getHeaders();
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testAuthorizeCorsResponse() {
        $result = $this->controller->authorizeCorsResponse();
        
        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        
        $headers = $result->getHeaders();
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testRegisterCorsResponse() {
        $result = $this->controller->registerCorsResponse();
        
        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        
        $headers = $result->getHeaders();
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testIntrospectionCorsResponse() {
        $result = $this->controller->introspectionCorsResponse();
        
        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        
        $headers = $result->getHeaders();
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testClientManagementCorsResponse() {
        $result = $this->controller->clientManagementCorsResponse();
        
        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        
        $headers = $result->getHeaders();
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testAllCorsMethodsReturnSameHeaders() {
        $methods = [
            'discoveryCorsResponse',
            'jwksCorsResponse',
            'userInfoCorsResponse',
            'logoutCorsResponse',
            'tokenCorsResponse',
            'authorizeCorsResponse',
            'registerCorsResponse',
            'introspectionCorsResponse',
            'clientManagementCorsResponse'
        ];
        
        $expectedHeaders = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'PUT, POST, GET, DELETE, PATCH',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept, OCS-APIRequest, Range, Depth, Destination, Overwrite, X-Requested-With',
            'Access-Control-Max-Age' => '1728000'
        ];
        
        foreach ($methods as $method) {
            $result = $this->controller->$method();
            $headers = $result->getHeaders();
            
            foreach ($expectedHeaders as $key => $expectedValue) {
                $this->assertArrayHasKey($key, $headers, "Method $method missing header $key");
                $this->assertEquals($expectedValue, $headers[$key], "Method $method has wrong value for header $key");
            }
        }
    }
}
