<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Listener;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use OCP\IURLGenerator;
use OCP\Server;
use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\IssuedIdTokenMapper;
use OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * @implements IEventListener<TokenValidationRequestEvent|Event>
 */
class TokenValidationRequestListener implements IEventListener {

    public function __construct(
        private LoggerInterface $logger,
        private ITimeFactory $time,
        private IAppConfig $appConfig,
        private IUserManager $userManager,
        private AccessTokenMapper $accessTokenMapper,
        private ClientMapper $clientMapper,
        private ?IURLGenerator $urlGenerator = null,
        private ?IssuedIdTokenMapper $issuedIdTokenMapper = null,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof TokenValidationRequestEvent) {
            return;
        }

        $event->setIsValid(false);
        $tokenString = $event->getToken();
        $this->logger->debug('[TokenValidationRequestListener] received a token validation request event');

        $expireTime = (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, Application::DEFAULT_EXPIRE_TIME);

        // check if it's an access token
        try {
            $accessToken = $this->accessTokenMapper->getByAccessToken($tokenString);
            $user = $this->userManager->get($accessToken->getUserId());
            $hasExpired = $this->time->getTime() >= $accessToken->getEffectiveExpiresAt($expireTime);
            if ($hasExpired || $user === null || !$user->isEnabled()) {
                $event->setIsValid(false);
            } else {
                $event->setIsValid(true);
                $event->setUserId($accessToken->getUserId());
            }
            // stop here if we found this access token
            return;
        } catch (AccessTokenNotFoundException $e) {
            // proceed checking for an id token
        }

        // Unverified fields select a verification key only; they never authorize.
        try {
            $parts = explode('.', $tokenString);
            if (count($parts) !== 3 || strlen($tokenString) > 65536) {
                return;
            }
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 32, JSON_THROW_ON_ERROR);
            $payload = json_decode(JWT::urlsafeB64Decode($parts[1]), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($header) || !is_array($payload)
                || !in_array($header['typ'] ?? 'JWT', ['JWT', 'application/jwt'], true)) {
                // In particular, a revoked at+jwt token must not enter this path.
                return;
            }
            $audiences = $payload['aud'] ?? null;
            $audiences = is_string($audiences) ? [$audiences] : $audiences;
            if (!is_array($audiences) || !array_is_list($audiences) || $audiences === []
                || count($audiences) > 16 || array_filter($audiences, static fn ($aud): bool => !is_string($aud) || $aud === '') !== []) {
                return;
            }
            $authorizedParty = $payload['azp'] ?? null;
            if ($authorizedParty !== null && (!is_string($authorizedParty) || !in_array($authorizedParty, $audiences, true))) {
                return;
            }
            if (count($audiences) > 1 && $authorizedParty === null) {
                return;
            }
            $client = $this->clientMapper->getByIdentifier($authorizedParty ?? $audiences[0]);
            if ($client === null || ($header['alg'] ?? null) !== $client->getSigningAlg()) {
                return;
            }
            if ($client->getSigningAlg() === 'HS256') {
                if ($client->getType() === 'public' || !$client->getSecret()) {
                    return;
                }
                if (!($this->issuedIdTokenMapper ?? Server::get(IssuedIdTokenMapper::class))->isIssued($tokenString, $this->time->getTime())) {
                    return;
                }
                $keys = new Key($client->getSecret(), 'HS256');
            } elseif ($client->getSigningAlg() === 'RS256') {
                $keys = JWK::parseKeySet(['keys' => [[
                    'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256',
                    'kid' => $this->appConfig->getAppValueString('kid'),
                    'n' => $this->appConfig->getAppValueString('public_key_n'),
                    'e' => $this->appConfig->getAppValueString('public_key_e'),
                ]]]);
            } else {
                return;
            }
            $claims = (array)JWT::decode($tokenString, $keys);
            $issuer = rtrim(($this->urlGenerator ?? Server::get(IURLGenerator::class))->getAbsoluteURL(''), '/');
            $now = $this->time->getTime();
            if (($claims['iss'] ?? null) !== $issuer || !is_string($claims['sub'] ?? null) || $claims['sub'] === ''
                || !is_int($claims['exp'] ?? null) || !is_int($claims['iat'] ?? null)
                || $claims['exp'] <= $now || $claims['iat'] > $now || $claims['exp'] <= $claims['iat']) {
                return;
            }
            $user = $this->userManager->get($claims['sub']);
            if ($user !== null && $user->isEnabled()) {
                $event->setIsValid(true);
                $event->setUserId($claims['sub']);
            }
        } catch (ClientNotFoundException | \InvalidArgumentException | \DomainException | \UnexpectedValueException | \JsonException $e) {
            $this->logger->debug('ID token validation failed.', ['exception' => get_class($e)]);
        }
    }
}
