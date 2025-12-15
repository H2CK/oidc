<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use OCA\OIDCIdentityProvider\Service\RedirectUriService;

use Psr\Log\LoggerInterface;

class RedirectUriServiceTest extends TestCase {
        protected $service;

        /** @var LoggerInterface */
        private $logger;

    public function setUp(): void {
        $this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();

        $this->service = new RedirectUriService(
            $this->logger
        );
    }

        public static function wildcardProviderPositive(): array
    {
        return [
            'Case 1-1' => ['https://example.com/callback', false],
            'Case 1-2' => ['https://example.com/callback', true],
            'Case 2-1' => ['http://localhost:*/callback', false],
            'Case 2-2' => ['http://localhost:*/callback', true],
            'Case 3-1' => ['https://*.example.com/callback', true],
            'Case 4-1' => ['https://example.com/callback/*', false],
            'Case 4-2' => ['https://example.com/callback/*', true],
            'Case 5-1' => ['https://*.example.com/callback/*', true],
            'Case 6-1' => ['app://example.com:8080/callback', false],
            'Case 6-2' => ['app://example.com:8080/callback', true],
            'Case 7-1' => ['app.immich:///oauth-callback', false],
            'Case 7-2' => ['app.immich:///oauth-callback', true],
            'Case 8-1' => ['app.immich:///oauth-callback/*', false],
            'Case 9-1' => ['https://example.com/wp-admin/admin-ajax.php?action=openid-connect-authorize', true],
        ];
    }

    public static function wildcardProviderNegative(): array
    {
        return [
            'Case 1-1' => ['https://exa*mple.com/callback', false],
            'Case 1-2' => ['https://exa*mple.com/callback', true],
            'Case 2-1' => ['https://example.com/ca*llback/more', false],
            'Case 3-1' => ['https://*.example.com/callback', false],
            'Case 4-1' => ['https://example.*.com/callback', true],
            'Case 5-1' => ['https://*.example.com:*/callback', true],
            'Case 6-1' => ['https://example.com/callback*', false],
            'Case 7-1' => ['app.immich:///callback*', false],
        ];
    }

    public static function redirectUriProviderPositive(): array
    {
        return [
            'Case 1-1' => ['https://example.com/callback', 'https://example.com/callback'],
            'Case 2-1' => ['http://localhost:8080/callback', 'http://localhost:8080/callback'],
            'Case 3-1' => ['https://sub.example.com/callback', 'https://*.example.com/callback'],
            'Case 4-1' => ['https://example.com/callback/more', 'https://example.com/callback/*'],
            'Case 5-1' => ['https://sub.example.com/callback/more', 'https://*.example.com/callback/*'],
            'Case 6-1' => ['app://example.com:8080/callback', 'app://example.com:8080/callback'],
            'Case 7-1' => ['app://example.com:8080/callback/YESS', 'app://example.com:8080/callback/*'],
            'Case 8-1' => ['app.immich:///oauth-callback', 'app.immich:///oauth-callback'],
            'Case 9-1' => ['app.immich:///oauth-callback/extra', 'app.immich:///oauth-callback/*'],
            'Case 10-1'=> ['https://example.com/wp-admin/admin-ajax.php?action=openid-connect-authorize', 'https://example.com/wp-admin/admin-ajax.php?action=openid-connect-authorize'],
        ];
    }

    public static function redirectUriProviderNegative(): array
    {
        return [
            'Case 1-1' => ['https://example.com/callback', 'https://example.org/callback'],
            'Case 2-1' => ['http://localhost:8080/callback', 'http://localhost:9090/callback'],
            'Case 3-1' => ['https://example.com/callback', 'https://*.example.com/callback'],
            'Case 4-1' => ['https://sub.example.com/callback', 'https://example.com/callback'],
            'Case 5-1' => ['https://example.com/callback/more', 'https://example.com/callback'],
            'Case 6-1' => ['https://sub.example.com/callback/more', 'https://*.example.com/callback'],
            'Case 7-1' => ['app://example.com:8080/callback', 'app://example.com:9090/callback'],
            'Case 8-1' => ['app://example.com:8080/callback', 'app://*.example.com:8080/callback'],
            'Case 9-1' => ['app://example.com:8080/callback/more', 'app://example.com:8080/callback'],
            'Case 10-1' => ['app.immich:///oauth-callback', 'app.immich:///other-callback'],
            'Case 10-2' => ['app.immich:///oauth-callback', 'app.immich:///oauth-callback/*'],
            'Case 11-1' => ['app.immich:///oauth-:///callback', 'app.immich:///oauth-callback'],
			'Case 12-1' => ['https://example.com/wp-admin/admin-ajax.php?action=openid-connect-authorize', 'https://example.com/wp-admin/*?action=openid-connect-authorize'],
        ];
    }

    #[DataProvider('wildcardProviderPositive')]
    public function testRedirectUriWildcardsPositive(string $uri, bool $subdomain = false) {
        $this->assertTrue($this->service->isValidRedirectUri($uri, $subdomain), "Check for ".$uri." failed");
    }

    #[DataProvider('wildcardProviderNegative')]
    public function testRedirectUriWildcardsNegative(string $uri, bool $subdomain = false) {
        $this->expectException(\OCA\OIDCIdentityProvider\Exceptions\RedirectUriValidationException::class);
        $this->service->isValidRedirectUri($uri, $subdomain);
    }

    #[DataProvider('redirectUriProviderPositive')]
    public function testRedirectUriMatchPositive(string $uri, string $pattern) {
        $this->assertTrue($this->service->matchRedirectUri($uri, $pattern), "Match for ".$uri." failed");
    }

    #[DataProvider('redirectUriProviderNegative')]
    public function testRedirectUriMatchNegative(string $uri, string $pattern) {
        $this->assertFalse($this->service->matchRedirectUri($uri, $pattern), "Check for ".$uri." failed");
    }
}
