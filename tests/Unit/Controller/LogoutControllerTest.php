<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use Firebase\JWT\JWT;
use OCA\OIDCIdentityProvider\Controller\LogoutController;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUri;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LogoutControllerTest extends TestCase {
    /** @var LogoutController */
    private $controller;

    /** @var \PHPUnit\Framework\MockObject\MockObject|IUserSession */
    private $userSession;

    /** @var \PHPUnit\Framework\MockObject\MockObject|IUserManager */
    private $userManager;

    /** @var \PHPUnit\Framework\MockObject\MockObject|AccessTokenMapper */
    private $accessTokenMapper;

    /** @var \PHPUnit\Framework\MockObject\MockObject|LogoutRedirectUriMapper */
    private $logoutRedirectUriMapper;

    /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
    private $appConfig;

    /** @var string */
    private $privateKey;

    public function setUp(): void {
        $request = $this->createMock(IRequest::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $clientMapper = $this->createMock(ClientMapper::class);
        $session = $this->createMock(ISession::class);
        $l = $this->createMock(IL10N::class);
        $time = $this->createMock(ITimeFactory::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->accessTokenMapper = $this->createMock(AccessTokenMapper::class);
        $this->logoutRedirectUriMapper = $this->createMock(LogoutRedirectUriMapper::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->privateKey = $this->configureJwtKeys();

        $urlGenerator->method('linkToRoute')
            ->with('core.login.showLoginForm', [])
            ->willReturn('/login');
        $urlGenerator->method('getAbsoluteURL')
            ->with('/')
            ->willReturn('https://nextcloud.local');
        $this->controller = new LogoutController(
            'oidc',
            $request,
            $urlGenerator,
            $clientMapper,
            $session,
            $l,
            $time,
            $this->userSession,
            $this->userManager,
            $this->accessTokenMapper,
            $this->logoutRedirectUriMapper,
            $this->appConfig,
            $logger
        );
    }

    public function testLogoutUsesSubjectClaimWhenPreferredUsernameIsMissing(): void {
        $userId = 'user1';
        $clientId = 'client1';
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
        ]);
        $user = $this->createMock(IUser::class);

        $this->userManager->expects($this->once())
            ->method('get')
            ->with($userId)
            ->willReturn($user);
        $this->userSession->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(false);
        $this->accessTokenMapper->expects($this->once())
            ->method('deleteByUserId')
            ->with($userId);

        $result = $this->controller->logout($clientId, $idTokenHint);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertEquals('/login', $result->getRedirectURL());
    }

    public function testLogoutRejectsIdTokenHintWithoutSubject(): void {
        $idTokenHint = $this->createIdTokenHint([
            'aud' => 'client1',
        ]);

        $this->userManager->expects($this->never())->method('get');
        $this->accessTokenMapper->expects($this->never())->method('deleteByUserId');

        $result = $this->controller->logout('client1', $idTokenHint);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        $this->assertEquals('invalid_jwt', $result->getData()['error']);
    }

    public function testLogoutDoesNotRedirectWithoutIdTokenHint(): void {
        $this->addRegisteredLogoutRedirectUri('https://rp.example/logout');

        $result = $this->controller->logout(null, null, 'https://rp.example/logout');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertEquals('/login', $result->getRedirectURL());
    }

    public function testLogoutRejectsInvalidIdTokenHintEvenWithActiveSession(): void {
        $this->addRegisteredLogoutRedirectUri('https://rp.example/logout');
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->userSession->method('getUser')->willReturn($user);
        $this->userSession->expects($this->once())->method('logout');

        $result = $this->controller->logout(null, 'not-a-jwt', 'https://rp.example/logout');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(Http::STATUS_UNAUTHORIZED, $result->getStatus());
    }

    public function testLogoutOnlyRedirectsToAnExactlyRegisteredUri(): void {
        $userId = 'user1';
        $clientId = 'client1';
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
        ]);
        $this->addRegisteredLogoutRedirectUri('https://rp.example/logout');
        $this->userManager->method('get')->with($userId)->willReturn($this->createMock(IUser::class));

        $result = $this->controller->logout($clientId, $idTokenHint, 'https://rp.example/logout?foo=bar');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertEquals('/login', $result->getRedirectURL());
    }

    public function testLogoutRedirectsWithValidIdTokenHintAndRegisteredUri(): void {
        $userId = 'user1';
        $clientId = 'client1';
        $idTokenHint = $this->createIdTokenHint([
            'sub' => $userId,
            'aud' => $clientId,
        ]);
        $this->addRegisteredLogoutRedirectUri('https://rp.example/logout');
        $this->userManager->method('get')->with($userId)->willReturn($this->createMock(IUser::class));

        $result = $this->controller->logout($clientId, $idTokenHint, 'https://rp.example/logout', 'state value');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertEquals('https://rp.example/logout?state=state+value', $result->getRedirectURL());
    }

    private function addRegisteredLogoutRedirectUri(string $uri): void {
        $logoutRedirectUri = new LogoutRedirectUri();
        $logoutRedirectUri->setRedirectUri($uri);
        $this->logoutRedirectUriMapper->method('getAll')->willReturn([$logoutRedirectUri]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function createIdTokenHint(array $claims): string {
        $payload = array_merge([
            'iss' => 'https://nextcloud.local',
            'exp' => time() + 3600,
            'iat' => time(),
        ], $claims);

        return JWT::encode($payload, $this->privateKey, 'RS256', 'test-kid');
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

        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function ($key, $default = '') use ($modulus, $exponent) {
                $config = [
                    'kid' => 'test-kid',
                    'public_key_n' => $modulus,
                    'public_key_e' => $exponent,
                ];
                return $config[$key] ?? $default;
            });

        return $privateKey;
    }
}
