<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Listener;

use Firebase\JWT\JWT;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\IssuedIdTokenMapper;
use OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCA\OIDCIdentityProvider\Listener\TokenValidationRequestListener;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TokenValidationSecurityTest extends TestCase {
    private function validate(array $overrides = [], string $type = 'JWT', string $algorithm = 'RS256', bool $issued = true): TokenValidationRequestEvent {
        $now = time();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);
        openssl_pkey_export($key, $private);
        $secret = str_repeat('s', 64);
        $client = new Client(signingAlg: $algorithm);
        $client->setClientIdentifier('client');
        $client->setSecret($secret);
        $claims = array_replace(['iss' => 'https://op.example', 'sub' => 'alice', 'aud' => 'client', 'iat' => $now, 'exp' => $now + 300], $overrides);
        $token = JWT::encode($claims, $algorithm === 'HS256' ? $secret : $private, $algorithm, $algorithm === 'HS256' ? null : 'key', ['typ' => $type]);
        $clock = $this->createMock(ITimeFactory::class);
        $clock->method('getTime')->willReturn($now);
        $config = $this->createMock(IAppConfig::class);
        $config->method('getAppValueString')->willReturnCallback(static fn ($name, $default = ''): string => [
            'kid' => 'key', 'public_key_n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'public_key_e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ][$name] ?? $default);
        $clients = $this->createMock(ClientMapper::class);
        $clients->method('getByIdentifier')->with('client')->willReturn($client);
        $accessTokens = $this->createMock(AccessTokenMapper::class);
        $accessTokens->method('getByAccessToken')->willThrowException(new AccessTokenNotFoundException());
        $users = $this->createMock(IUserManager::class);
        $user = $this->createMock(IUser::class);
        $user->method('isEnabled')->willReturn(true);
        $users->method('get')->willReturnCallback(static fn ($uid) => $uid === 'alice' ? $user : null);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('getAbsoluteURL')->willReturn('https://op.example/');
        $issuance = $this->createMock(IssuedIdTokenMapper::class);
        $issuance->method('isIssued')->willReturn($issued);
        $listener = new TokenValidationRequestListener($this->createMock(LoggerInterface::class), $clock, $config, $users, $accessTokens, $clients, $urls, $issuance);
        $event = new TokenValidationRequestEvent($token);
        $listener->handle($event);
        return $event;
    }

    public function testRevokedSignedAccessTokenCannotEnterIdTokenFallback(): void {
        $this->assertFalse($this->validate([], 'at+jwt')->getIsValid());
    }

    public function testSubjectIsTheIdentityEvenWithoutOrWithMisleadingUsername(): void {
        foreach ([[], ['preferred_username' => 'bob']] as $extra) {
            $event = $this->validate($extra);
            $this->assertTrue($event->getIsValid());
            $this->assertSame('alice', $event->getUserId());
        }
    }

    public function testIssuerAndRequiredClaimsAreChecked(): void {
        foreach ([['iss' => 'https://other.example'], ['exp' => null], ['iat' => null], ['sub' => null], ['aud' => 123]] as $invalid) {
            $this->assertFalse($this->validate($invalid)->getIsValid());
        }
    }

    public function testHmacTokensRequireProofOfLocalIssuance(): void {
        $this->assertTrue($this->validate([], 'JWT', 'HS256', true)->getIsValid());
        $this->assertFalse($this->validate([], 'JWT', 'HS256', false)->getIsValid());
    }
}
