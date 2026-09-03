<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use Firebase\JWT\JWT;
use OCA\OIDCIdentityProvider\Controller\LogoutController;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUri;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LogoutControllerTest extends TestCase {
    private LogoutController $controller;
    private IUserSession $userSession;
    private IUserManager $userManager;
    private LogoutRedirectUriMapper $logoutRedirectUriMapper;
    private IAppConfig $appConfig;
    private ClientMapper $clientMapper;
    private BackChannelLogoutService $backChannelLogoutService;
    private FrontChannelLogoutService $frontChannelLogoutService;
    private SessionManagementService $sessionManagementService;
    private string $privateKey;

    /** @var array<string, mixed> */
    private array $sessionData = [];

    protected function setUp(): void {
        $request = $this->createMock(IRequest::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $session = $this->createMock(ISession::class);
        $l = $this->createMock(IL10N::class);
        $time = $this->createMock(ITimeFactory::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->logoutRedirectUriMapper = $this->createMock(LogoutRedirectUriMapper::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $logger = $this->createMock(LoggerInterface::class);
        $secureRandom = $this->createMock(ISecureRandom::class);
        $this->backChannelLogoutService = $this->createMock(BackChannelLogoutService::class);
        $this->frontChannelLogoutService = $this->createMock(FrontChannelLogoutService::class);
        $this->sessionManagementService = $this->createMock(SessionManagementService::class);

        $this->privateKey = $this->configureJwtKeys();

        $urlGenerator->method('linkToRoute')->willReturnCallback(
            static fn (string $route, array $params = []): string => match ($route) {
                'core.login.showLoginForm' => '/login',
                'oidc.Logout.logoutPost' => '/index.php/apps/oidc/logout',
                default => '/' . $route,
            }
        );
        $urlGenerator->method('getAbsoluteURL')->with('/')->willReturn('https://nextcloud.local/');
        $urlGenerator->method('getWebroot')->willReturn('');
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('nextcloud.local');
        $l->method('t')->willReturnCallback(static fn (string $text): string => $text);
        $time->method('getTime')->willReturn(1000);
        $secureRandom->method('generate')->willReturn('confirmation-token');

        $session->method('get')->willReturnCallback(fn (string $key) => $this->sessionData[$key] ?? null);
        $session->method('set')->willReturnCallback(function (string $key, mixed $value): void {
            $this->sessionData[$key] = $value;
        });
        $session->method('remove')->willReturnCallback(function (string $key): void {
            unset($this->sessionData[$key]);
        });

        $this->controller = new LogoutController(
            'oidc',
            $request,
            $urlGenerator,
            $this->clientMapper,
            $session,
            $l,
            $time,
            $this->userSession,
            $this->userManager,
            $this->logoutRedirectUriMapper,
            $this->appConfig,
            $logger,
            $secureRandom,
            $this->backChannelLogoutService,
            $this->frontChannelLogoutService,
            $this->sessionManagementService,
        );
    }

    public function testLogoutWithoutHintRequiresConfirmationAndDoesNotLogout(): void {
        $this->configureActiveUser('user1');
        $this->userSession->expects($this->never())->method('logout');

        $result = $this->controller->logout();

        $this->assertInstanceOf(TemplateResponse::class, $result);
        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('oidc', $result->getApp());
        $this->assertSame('logout_confirmation', $result->getTemplateName());
        $this->assertSame(TemplateResponse::RENDER_AS_GUEST, $result->getRenderAs());
        $this->assertSame([
            'action' => '/index.php/apps/oidc/logout',
            'cancelUrl' => 'https://nextcloud.local/',
            'confirmationToken' => 'confirmation-token',
            'title' => 'Confirm logout',
            'message' => 'A relying party requested to end your OpenID Connect session. Do you want to log out?',
            'logoutLabel' => 'Log out',
            'cancelLabel' => 'Cancel',
        ], $result->getParams());
    }

    public function testConfirmedLogoutConsumesOneTimeTokenBeforeLoggingOut(): void {
        $this->configureActiveUser('user1');
        $this->userSession->expects($this->once())->method('logout');

        $confirmation = $this->controller->logout();
        $this->assertInstanceOf(TemplateResponse::class, $confirmation);

        $result = $this->controller->logout(null, null, null, null, '1', 'confirmation-token');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('/login', $result->getRedirectURL());

        $replay = $this->controller->logout(null, null, null, null, '1', 'confirmation-token');
        $this->assertInstanceOf(JSONResponse::class, $replay);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $replay->getStatus());
    }

    public function testInvalidIdTokenHintWithActiveSessionRequiresConfirmationWithoutLogout(): void {
        $this->configureActiveUser('user1');
        $this->userSession->expects($this->never())->method('logout');

        $result = $this->controller->logout(null, 'not-a-jwt');

        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

    public function testValidIdTokenHintMustMatchCurrentSidBeforeSilentLogout(): void {
        $userId = 'user1';
        $clientId = 'client1';
        $client = $this->newClient($clientId);
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
            'sid' => 'sid-1',
        ]);

        $this->configureActiveUser($userId);
        $this->userManager->method('get')->with($userId)->willReturn($this->createMock(IUser::class));
        $this->clientMapper->method('getByIdentifier')->with($clientId)->willReturn($client);
        $this->backChannelLogoutService->expects($this->once())
            ->method('isCurrentClientSession')
            ->with($client, 'sid-1')
            ->willReturn(true);
        $this->backChannelLogoutService->expects($this->once())
            ->method('getCurrentClientSessions')
            ->willReturn([]);
        $this->frontChannelLogoutService->expects($this->once())
            ->method('getLogoutUris')
            ->with([])
            ->willReturn([]);
        $this->frontChannelLogoutService->expects($this->never())
            ->method('createBrowserLogoutResponse');
        $this->userSession->expects($this->once())->method('logout');

        $result = $this->controller->logout($clientId, $idTokenHint);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('/login', $result->getRedirectURL());
    }

    public function testMismatchingSidRequiresConfirmationWithoutLogout(): void {
        $userId = 'user1';
        $clientId = 'client1';
        $client = $this->newClient($clientId);
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
            'sid' => 'old-sid',
        ]);

        $this->configureActiveUser($userId);
        $this->userManager->method('get')->with($userId)->willReturn($this->createMock(IUser::class));
        $this->clientMapper->method('getByIdentifier')->with($clientId)->willReturn($client);
        $this->backChannelLogoutService->method('isCurrentClientSession')->with($client, 'old-sid')->willReturn(false);
        $this->userSession->expects($this->never())->method('logout');

        $result = $this->controller->logout($clientId, $idTokenHint);

        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

    public function testValidHintWithoutActiveOpSessionRequiresRecentSessionAndDoesNotRevokeGrants(): void {
        $userId = 'user1';
        $clientId = 'client1';
        $client = $this->newClient($clientId);
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
            'sid' => 'sid-recent',
        ]);

        $this->userSession->method('isLoggedIn')->willReturn(false);
        $this->userManager->method('get')->with($userId)->willReturn($this->createMock(IUser::class));
        $this->clientMapper->method('getByIdentifier')->with($clientId)->willReturn($client);
        $this->backChannelLogoutService->expects($this->once())
            ->method('isRecentClientSession')
            ->with($client, $userId, 'sid-recent')
            ->willReturn(true);
        $this->userSession->expects($this->never())->method('logout');

        $result = $this->controller->logout($clientId, $idTokenHint);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('/login', $result->getRedirectURL());
    }

    public function testLogoutRejectsIdTokenHintWithoutSubjectWhenNoActiveSession(): void {
        $this->userSession->method('isLoggedIn')->willReturn(false);
        $idTokenHint = $this->createIdTokenHint(['aud' => 'client1']);

        $this->userManager->expects($this->never())->method('get');

        $result = $this->controller->logout('client1', $idTokenHint);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
    }

    public function testLogoutDoesNotRedirectWithoutIdTokenHintWhenNoActiveSession(): void {
        $this->userSession->method('isLoggedIn')->willReturn(false);
        $this->addRegisteredLogoutRedirectUri('https://rp.example/logout');

        $result = $this->controller->logout(null, null, 'https://rp.example/logout');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('/login', $result->getRedirectURL());
    }

    public function testLogoutRedirectsWithValidHintAndExactlyRegisteredUri(): void {
        $userId = 'user1';
        $clientId = 'client1';
        $client = $this->newClient($clientId);
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
            'sid' => 'sid-recent',
        ]);

        $this->userSession->method('isLoggedIn')->willReturn(false);
        $this->userManager->method('get')->with($userId)->willReturn($this->createMock(IUser::class));
        $this->clientMapper->method('getByIdentifier')->with($clientId)->willReturn($client);
        $this->backChannelLogoutService->method('isRecentClientSession')->with($client, $userId, 'sid-recent')->willReturn(true);
        $this->addRegisteredLogoutRedirectUri('https://rp.example/logout');

        $result = $this->controller->logout($clientId, $idTokenHint, 'https://rp.example/logout', 'state value');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('https://rp.example/logout?state=state+value', $result->getRedirectURL());
    }

    public function testLogoutDoesNotUseGlobalUriWhenRpSpecificListExists(): void {
        $userId = 'user1';
        $clientId = 'client1';
        $client = $this->newClient($clientId);
        $idTokenHint = $this->createIdTokenHint(['sub' => $userId, 'aud' => $clientId, 'sid' => 'sid-recent']);

        $specific = new LogoutRedirectUri();
        $specific->setRedirectUri('https://rp.example/own-logout');
        $this->logoutRedirectUriMapper->expects($this->once())
            ->method('getEffectiveByClientId')
            ->with(7)
            ->willReturn([$specific]);
        $this->userSession->method('isLoggedIn')->willReturn(false);
        $this->userManager->method('get')->with($userId)->willReturn($this->createMock(IUser::class));
        $this->clientMapper->method('getByIdentifier')->with($clientId)->willReturn($client);
        $this->backChannelLogoutService->method('isRecentClientSession')->with($client, $userId, 'sid-recent')->willReturn(true);

        $result = $this->controller->logout($clientId, $idTokenHint, 'https://global.example/logout');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('/login', $result->getRedirectURL());
    }

    public function testHs256IdTokenHintUsesClientSecretAndCanLogoutCurrentSid(): void {
        $userId = 'user1';
        $clientId = 'client-hs';
        $secret = '0123456789abcdef0123456789abcdef';
        $client = $this->newClient($clientId, 'HS256', $secret);
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
            'sid' => 'sid-hs',
        ], 'HS256', $secret);

        $this->configureActiveUser($userId);
        $this->userManager->method('get')->with($userId)->willReturn($this->createMock(IUser::class));
        $this->clientMapper->method('getByIdentifier')->with($clientId)->willReturn($client);
        $this->backChannelLogoutService->method('isCurrentClientSession')->with($client, 'sid-hs')->willReturn(true);
        $this->userSession->expects($this->once())->method('logout');

        $result = $this->controller->logout($clientId, $idTokenHint);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    public function testSigningAlgorithmMustMatchClientConfiguration(): void {
        $userId = 'user1';
        $clientId = 'client1';
        $secret = '0123456789abcdef0123456789abcdef';
        $client = $this->newClient($clientId, 'RS256');
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
            'sid' => 'sid-1',
        ], 'HS256', $secret);

        $this->configureActiveUser($userId);
        $this->clientMapper->method('getByIdentifier')->with($clientId)->willReturn($client);
        $this->userSession->expects($this->never())->method('logout');

        $result = $this->controller->logout($clientId, $idTokenHint);

        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

    public function testExpiredIdTokenHintCanLogoutOnlyWhenSidMatchesCurrentSession(): void {
        $userId = 'user1';
        $clientId = 'client1';
        $client = $this->newClient($clientId);
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
            'sid' => 'sid-current',
            'iat' => time() - 7200,
            'exp' => time() - 60,
        ]);

        $this->configureActiveUser($userId);
        $this->userManager->method('get')->with($userId)->willReturn($this->createMock(IUser::class));
        $this->clientMapper->method('getByIdentifier')->with($clientId)->willReturn($client);
        $this->backChannelLogoutService->method('isCurrentClientSession')->with($client, 'sid-current')->willReturn(true);
        $this->userSession->expects($this->once())->method('logout');

        $result = $this->controller->logout($clientId, $idTokenHint);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    public function testExpiredIdTokenHintWithoutActiveOpSessionIsAcceptedForRecentSession(): void {
        $userId = 'user1';
        $clientId = 'client1';
        $client = $this->newClient($clientId);
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
            'sid' => 'recent-sid',
            'iat' => time() - 7200,
            'exp' => time() - 60,
        ]);

        $this->userSession->method('isLoggedIn')->willReturn(false);
        $this->userManager->method('get')->with($userId)->willReturn($this->createMock(IUser::class));
        $this->clientMapper->method('getByIdentifier')->with($clientId)->willReturn($client);
        $this->backChannelLogoutService->expects($this->once())
            ->method('isRecentClientSession')
            ->with($client, $userId, 'recent-sid')
            ->willReturn(true);

        $result = $this->controller->logout($clientId, $idTokenHint);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('/login', $result->getRedirectURL());
    }

    public function testExpiredIdTokenHintWithoutCurrentOrRecentSessionIsRejected(): void {
        $userId = 'user1';
        $clientId = 'client1';
        $client = $this->newClient($clientId);
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
            'sid' => 'old-sid',
            'iat' => time() - 7200,
            'exp' => time() - 60,
        ]);

        $this->userSession->method('isLoggedIn')->willReturn(false);
        $this->userManager->method('get')->with($userId)->willReturn($this->createMock(IUser::class));
        $this->clientMapper->method('getByIdentifier')->with($clientId)->willReturn($client);
        $this->backChannelLogoutService->expects($this->once())
            ->method('isRecentClientSession')
            ->with($client, $userId, 'old-sid')
            ->willReturn(false);

        $result = $this->controller->logout($clientId, $idTokenHint);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
    }

    public function testClientIdWithoutHintCanRedirectAfterExplicitConfirmation(): void {
        $client = $this->newClient('client1');
        $this->configureActiveUser('user1');
        $this->clientMapper->method('getByIdentifier')->with('client1')->willReturn($client);
        $this->addRegisteredLogoutRedirectUri('https://rp.example/logout');
        $this->userSession->expects($this->once())->method('logout');

        $confirmation = $this->controller->logout('client1', null, 'https://rp.example/logout', 'state value');
        $this->assertInstanceOf(TemplateResponse::class, $confirmation);

        $result = $this->controller->logout(null, null, null, null, '1', 'confirmation-token');
        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('https://rp.example/logout?state=state+value', $result->getRedirectURL());
    }

    public function testClientIdWithoutHintCanRedirectWhenAlreadyLoggedOut(): void {
        $client = $this->newClient('client1');
        $this->userSession->method('isLoggedIn')->willReturn(false);
        $this->clientMapper->method('getByIdentifier')->with('client1')->willReturn($client);
        $this->addRegisteredLogoutRedirectUri('https://rp.example/logout');
        $this->userSession->expects($this->never())->method('logout');

        $result = $this->controller->logout('client1', null, 'https://rp.example/logout', 'state value');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertSame('https://rp.example/logout?state=state+value', $result->getRedirectURL());
    }

    public function testHs256HintWithMissingClientSecretFailsSafely(): void {
        $userId = 'user1';
        $clientId = 'client-hs';
        $signingSecret = '0123456789abcdef0123456789abcdef';
        $client = $this->newClient($clientId, 'HS256', '');
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
            'sid' => 'sid-hs',
        ], 'HS256', $signingSecret);

        $this->userSession->method('isLoggedIn')->willReturn(false);
        $this->clientMapper->method('getByIdentifier')->with($clientId)->willReturn($client);

        $result = $this->controller->logout($clientId, $idTokenHint);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
    }


    public function testSilentLogoutRendersFrontChannelIframesAndResetsSessionManagementState(): void {
        $userId = 'user1';
        $clientId = 'client1';
        $client = $this->newClient($clientId);
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
            'sid' => 'sid-1',
        ]);

        $this->configureActiveUser($userId);
        $this->userManager->method('get')->with($userId)->willReturn($this->createMock(IUser::class));
        $this->clientMapper->method('getByIdentifier')->with($clientId)->willReturn($client);
        $this->backChannelLogoutService->method('isCurrentClientSession')->with($client, 'sid-1')->willReturn(true);
        $this->backChannelLogoutService->expects($this->once())
            ->method('getCurrentClientSessions')
            ->willReturn(['7' => 'sid-1']);
        $this->frontChannelLogoutService->expects($this->once())
            ->method('getLogoutUris')
            ->with(['7' => 'sid-1'])
            ->willReturn(['https://rp.example/frontchannel?iss=https%3A%2F%2Fnextcloud.local&sid=sid-1']);
        $frontChannelResponse = new DataDisplayResponse(
            '<!doctype html><iframe src="https://rp.example/frontchannel?iss=https%3A%2F%2Fnextcloud.local&amp;sid=sid-1"></iframe><meta http-equiv="refresh">',
            Http::STATUS_OK,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
        $frontChannelResponse->addHeader('Content-Security-Policy', "default-src 'none'; frame-src https://rp.example");
        $frontChannelResponse->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->frontChannelLogoutService->expects($this->once())
            ->method('createBrowserLogoutResponse')
            ->with(
                ['https://rp.example/frontchannel?iss=https%3A%2F%2Fnextcloud.local&sid=sid-1'],
                '/login'
            )
            ->willReturn($frontChannelResponse);
        $this->sessionManagementService->expects($this->never())->method('rotateBrowserStateForResponse');
        $this->sessionManagementService->expects($this->never())->method('applyBrowserStateCookie');
        $this->userSession->expects($this->once())->method('logout');

        $result = $this->controller->logout($clientId, $idTokenHint);

        $this->assertInstanceOf(DataDisplayResponse::class, $result);
        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertStringContainsString('https://rp.example/frontchannel?iss=https%3A%2F%2Fnextcloud.local&amp;sid=sid-1', $result->getData());
        $this->assertStringContainsString('http-equiv="refresh"', $result->getData());
        $headers = $result->getHeaders();
        $this->assertStringContainsString('frame-src https://rp.example', $headers['Content-Security-Policy']);
        $this->assertSame('no-store, no-cache, must-revalidate', $headers['Cache-Control']);
    }

    private function configureActiveUser(string $userId): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function addRegisteredLogoutRedirectUri(string $uri): void {
        $logoutRedirectUri = new LogoutRedirectUri();
        $logoutRedirectUri->setRedirectUri($uri);
        $this->logoutRedirectUriMapper->method('getEffectiveByClientId')->with(7)->willReturn([$logoutRedirectUri]);
    }

    /** @param array<string, mixed> $claims */
    private function createIdTokenHint(array $claims, string $algorithm = 'RS256', ?string $secret = null): string {
        $payload = array_merge([
            'iss' => 'https://nextcloud.local',
            'exp' => time() + 3600,
            'iat' => time(),
        ], $claims);

        if ($algorithm === 'HS256') {
            return JWT::encode($payload, (string)$secret, 'HS256');
        }

        return JWT::encode($payload, $this->privateKey, 'RS256', 'test-kid');
    }

    private function newClient(string $identifier, string $algorithm = 'RS256', string $secret = '0123456789abcdef0123456789abcdef'): Client {
        $client = new Client('', [], $algorithm, 'confidential', 'code');
        $client->setId(7);
        $client->setClientIdentifier($identifier);
        $client->setSecret($secret);
        return $client;
    }

    private function configureJwtKeys(): string {
        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($keyPair, $privateKey);
        $details = openssl_pkey_get_details($keyPair);

        $modulus = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($details['rsa']['n']));
        $exponent = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($details['rsa']['e']));

        $this->appConfig->method('getAppValueString')->willReturnCallback(
            static function (string $key, string $default = '') use ($modulus, $exponent): string {
                $config = [
                    'kid' => 'test-kid',
                    'public_key_n' => $modulus,
                    'public_key_e' => $exponent,
                ];
                return $config[$key] ?? $default;
            }
        );

        return $privateKey;
    }
}
