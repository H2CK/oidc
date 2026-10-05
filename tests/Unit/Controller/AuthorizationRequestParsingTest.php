<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use OCA\OIDCIdentityProvider\Controller\LoginRedirectorController;
use OCA\OIDCIdentityProvider\Service\AuthorizationService;
use OCA\OIDCIdentityProvider\Service\AuthorizationTransactionService;
use OCA\OIDCIdentityProvider\Util\FormUrlencodedParameterParser;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class AuthorizationRequestParsingTest extends TestCase {
    private function controller(string $uri, string $body, AuthorizationService $service, ?AuthorizationTransactionService $transactions = null): LoginRedirectorController {
        $request = $this->createMock(IRequest::class);
        $request->method('getRequestUri')->willReturn($uri);
        $request->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === 'Content-Type' ? 'application/x-www-form-urlencoded' : '');
        $parser = $this->getMockBuilder(FormUrlencodedParameterParser::class)->onlyMethods(['readSelectedParameters'])->getMock();
        $parser->method('readSelectedParameters')->willReturnCallback(fn (array $names): array => $parser->parseSelectedParameters($body, $names));
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRoute')->willReturn('/apps/oidc/authorize/post/complete?t=' . str_repeat('a', 64));
        return new LoginRedirectorController('oidc', $request, $service, $parser, $transactions, $urls);
    }

    public function testRepeatedGetSingletonIsRejectedBeforeAuthorization(): void {
        $service = $this->createMock(AuthorizationService::class);
        $service->expects($this->never())->method('process');
        $response = $this->controller('/authorize?client_id=a&client_id=b', '', $service)->authorize();
        $this->assertSame(400, $response->getStatus());
    }

    public function testQueryAndPostOccurrencesAreCheckedTogether(): void {
        $service = $this->createMock(AuthorizationService::class);
        $service->expects($this->never())->method('process');
        $response = $this->controller('/authorize?state=a', 'state=b', $service)->authorizePost();
        $this->assertSame(400, $response->getStatus());
    }

    public function testPostPreservesRequestInOneTimeHandoffWithoutProcessingIt(): void {
        $service = $this->createMock(AuthorizationService::class);
        $service->expects($this->never())->method('process');
        $transactions = $this->createMock(AuthorizationTransactionService::class);
        $transactions->expects($this->once())->method('create')
            ->with($this->callback(static fn (array $p): bool => $p['scope'] === 'openid Files:Read'
                && $p['state'] === 'a+b&' && $p['response_mode'] === 'form_post' && !isset($p['unknown'])), 'authorization_post')
            ->willReturn(str_repeat('a', 64));
        $response = $this->controller('/authorize?client_id=client',
            'scope=openid+Files%3ARead&state=a%2Bb%26&response_mode=form_post&unknown=ignored', $service, $transactions)->authorizePost();
        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('authorization-handoff', $response->getTemplateName());
        $this->assertStringContainsString('/authorize/post/complete', $response->getParams()['continueUrl']);
        $this->assertSame('no-store', $response->getHeaders()['Cache-Control']);
    }

    public function testGetPreservesCaseAndEmptyStateAndIgnoresUnknownParameters(): void {
        $service = $this->createMock(AuthorizationService::class);
        $service->expects($this->once())->method('process')
            ->with($this->callback(static fn (array $p): bool => $p['scope'] === 'Files:Read' && $p['state'] === '' && !isset($p['unknown'])))
            ->willReturn(new RedirectResponse('https://rp.example/cb'));
        $this->controller('/authorize?scope=Files%3ARead&state=&unknown=x', '', $service)->authorize();
    }
}
