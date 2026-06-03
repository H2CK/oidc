<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\ISession;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;

use OCA\OIDCIdentityProvider\Controller\PageController;

class PageControllerTest extends TestCase {
    
    /** @var PageController */
    protected $controller;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IRequest */
    protected $request;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|ISession */
    private $session;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IL10N */
    private $l;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    private $time;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IUserSession */
    private $userSession;

    public function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->session = $this->createMock(ISession::class);
        $this->l = $this->createMock(IL10N::class);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->userSession = $this->createMock(IUserSession::class);
        
        $this->controller = new PageController(
            'oidc',
            $this->request,
            $this->session,
            $this->l,
            $this->time,
            $this->userSession
        );
    }

    public function testIndexReturnsTemplateResponse() {
        $this->request->method('getParam')
            ->willReturnMap([
                ['client_id', 'test-client'],
                ['state', 'test-state'],
                ['response_type', 'code'],
                ['redirect_uri', 'https://callback'],
                ['scope', 'openid profile'],
                ['nonce', 'test-nonce'],
                ['resource', null],
                ['code_challenge', 'test-challenge'],
                ['code_challenge_method', 'S256']
            ]);
        
        $result = $this->controller->index();
        
        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

    public function testIndexWithUrlParameters() {
        $this->request->method('getParam')
            ->willReturnMap([
                ['client_id', 'test-client'],
                ['state', 'test-state'],
                ['response_type', 'code'],
                ['redirect_uri', 'https://callback'],
                ['scope', 'openid profile'],
                ['nonce', 'test-nonce'],
                ['resource', 'resource1'],
                ['code_challenge', 'test-challenge'],
                ['code_challenge_method', 'S256']
            ]);
        
        $result = $this->controller->index();
        
        $this->assertInstanceOf(TemplateResponse::class, $result);
        
        // Verify the parameters are passed to the template
        $data = $result->getParams();
        $this->assertArrayHasKey('client_id', $data);
        $this->assertEquals('test-client', $data['client_id']);
        $this->assertArrayHasKey('state', $data);
        $this->assertEquals('test-state', $data['state']);
        $this->assertArrayHasKey('response_type', $data);
        $this->assertEquals('code', $data['response_type']);
        $this->assertArrayHasKey('redirect_uri', $data);
        $this->assertEquals('https://callback', $data['redirect_uri']);
        $this->assertArrayHasKey('scope', $data);
        $this->assertEquals('openid profile', $data['scope']);
        $this->assertArrayHasKey('nonce', $data);
        $this->assertEquals('test-nonce', $data['nonce']);
        $this->assertArrayHasKey('resource', $data);
        $this->assertEquals('resource1', $data['resource']);
        $this->assertArrayHasKey('code_challenge', $data);
        $this->assertEquals('test-challenge', $data['code_challenge']);
        $this->assertArrayHasKey('code_challenge_method', $data);
        $this->assertEquals('S256', $data['code_challenge_method']);
    }

    public function testIndexWithSessionParameters() {
        // No URL parameters, should use session
        $this->request->method('getParam')
            ->willReturn(null);
        
        $this->session->method('get')
            ->willReturnMap([
                ['oidc_client_id', 'session-client'],
                ['oidc_state', 'session-state'],
                ['oidc_response_type', 'code id_token'],
                ['oidc_redirect_uri', 'https://session-callback'],
                ['oidc_scope', 'openid profile email'],
                ['oidc_nonce', 'session-nonce'],
                ['oidc_resource', null],
                ['oidc_code_challenge', null],
                ['oidc_code_challenge_method', null]
            ]);
        
        $result = $this->controller->index();
        
        $this->assertInstanceOf(TemplateResponse::class, $result);
        
        // Verify the parameters are passed to the template
        $data = $result->getParams();
        $this->assertArrayHasKey('client_id', $data);
        $this->assertEquals('session-client', $data['client_id']);
        $this->assertArrayHasKey('state', $data);
        $this->assertEquals('session-state', $data['state']);
        $this->assertArrayHasKey('response_type', $data);
        $this->assertEquals('code id_token', $data['response_type']);
        $this->assertArrayHasKey('redirect_uri', $data);
        $this->assertEquals('https://session-callback', $data['redirect_uri']);
        $this->assertArrayHasKey('scope', $data);
        $this->assertEquals('openid profile email', $data['scope']);
        $this->assertArrayHasKey('nonce', $data);
        $this->assertEquals('session-nonce', $data['nonce']);
    }

    public function testIndexHasCorsHeaders() {
        $this->request->method('getParam')
            ->willReturn(null);
        
        $this->session->method('get')
            ->willReturn(null);
        
        $result = $this->controller->index();
        
        $headers = $result->getHeaders();
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
        $this->assertArrayHasKey('Access-Control-Allow-Methods', $headers);
        $this->assertEquals('GET', $headers['Access-Control-Allow-Methods']);
    }

    public function testIndexTemplateName() {
        $this->request->method('getParam')
            ->willReturn(null);
        
        $this->session->method('get')
            ->willReturn(null);
        
        $result = $this->controller->index();
        
        $this->assertEquals('oidc', $result->getApp());
        $this->assertEquals('main', $result->getTemplateName());
    }

    public function testIndexWithEmptySessionFallback() {
        // No URL parameters, and session returns null for some values
        $this->request->method('getParam')
            ->willReturn(null);
        
        $this->session->method('get')
            ->willReturn(null);
        
        $result = $this->controller->index();
        
        $this->assertInstanceOf(TemplateResponse::class, $result);
        
        // All parameters should be set (possibly to null)
        $data = $result->getParams();
        $this->assertArrayHasKey('client_id', $data);
        $this->assertArrayHasKey('state', $data);
        $this->assertArrayHasKey('response_type', $data);
        $this->assertArrayHasKey('redirect_uri', $data);
        $this->assertArrayHasKey('scope', $data);
        $this->assertArrayHasKey('nonce', $data);
        $this->assertArrayHasKey('resource', $data);
        $this->assertArrayHasKey('code_challenge', $data);
        $this->assertArrayHasKey('code_challenge_method', $data);
    }

    public function testIndexWithMissingOptionalParameters() {
        // Some parameters missing from URL
        $this->request->method('getParam')
            ->willReturnMap([
                ['client_id', 'test-client'],
                ['state', null],
                ['response_type', null],
                ['redirect_uri', null],
                ['scope', null],
                ['nonce', null],
                ['resource', null],
                ['code_challenge', null],
                ['code_challenge_method', null]
            ]);
        
        $result = $this->controller->index();
        
        $this->assertInstanceOf(TemplateResponse::class, $result);
        
        $data = $result->getParams();
        $this->assertArrayHasKey('client_id', $data);
        $this->assertEquals('test-client', $data['client_id']);
        // Other parameters should be null
        $this->assertArrayHasKey('state', $data);
        $this->assertNull($data['state']);
    }
}
