<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Listener;

use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use InvalidArgumentException;
use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

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
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof TokenValidationRequestEvent) {
            return;
        }

        $tokenString = $event->getToken();
        $this->logger->debug('[TokenValidationRequestListener] received a token validation request event');

        $expireTime = (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, Application::DEFAULT_EXPIRE_TIME);

        // check if it's an access token
        try {
            $accessToken = $this->accessTokenMapper->getByAccessToken($tokenString);
            $hasExpired = $this->time->getTime() > $accessToken->getRefreshed() + $expireTime;
            // cleanup expired access token
            if ($hasExpired) {
                $this->accessTokenMapper->delete($accessToken);
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

        // check if it's an id token
        $oidcKey = [
            'kty' => 'RSA',
            'use' => 'sig',
            'key_ops' => [ 'verify' ],
            'alg' => 'RS256',
            'kid' => $this->appConfig->getAppValueString('kid'),
            'n' => $this->appConfig->getAppValueString('public_key_n'),
            'e' => $this->appConfig->getAppValueString('public_key_e'),
        ];

        $jwks = [
            'keys' => [
                $oidcKey,
            ],
        ];

        $decodedJwt = null;
        try {
            $decodedStdClass = JWT::decode($tokenString, JWK::parseKeySet($jwks));
            $decodedJwt = (array) $decodedStdClass;
        } catch (InvalidArgumentException $e) {
            // provided key/key-array is empty or malformed.
            $this->logger->error('Provided key/key-array is empty or malformed.');
        } catch (DomainException $e) {
            // provided algorithm is unsupported OR
            // provided key is invalid OR
            // unknown error thrown in openSSL or libsodium OR
            // libsodium is required but not available.
            $this->logger->error('Provided algorithm is unsupported OR provided key is invalid OR unknown error thrown in openSSL or libsodium OR libsodium is required but not available.');
        } catch (SignatureInvalidException $e) {
            // provided JWT signature verification failed.
            $this->logger->error('Provided JWT signature verification failed.');
        } catch (BeforeValidException $e) {
            // provided JWT is trying to be used before "nbf" claim OR
            // provided JWT is trying to be used before "iat" claim.
            $this->logger->error('Provided JWT is trying to be used before "nbf" claim OR provided JWT is trying to be used before "iat" claim.');
        } catch (ExpiredException $e) {
            // provided JWT is trying to be used after "exp" claim.
            $this->logger->error('Provided JWT is trying to be used after "exp" claim.');
        } catch (UnexpectedValueException $e) {
            // provided JWT is malformed OR
            // provided JWT is missing an algorithm / using an unsupported algorithm OR
            // provided JWT algorithm does not match provided key OR
            // provided key ID in key/key-array is empty or invalid.
            $this->logger->error('Provided JWT is malformed OR provided JWT is missing an algorithm / using an unsupported algorithm OR provided JWT algorithm does not match provided key OR provided key ID in key/key-array is empty or invalid.');
        }

        if ($decodedJwt === null) {
            $this->logger->error('Provided JWT could not be decoded.');
            $event->setIsValid(false);
            return;
        }

        // check audience
        $audience = $decodedJwt['aud'] ?? '';
        try {
            $client = $this->clientMapper->getByIdentifier($audience);
            if ($client === null) {
                $this->logger->error('Token audience does not match any of our clients identifiers');
                $event->setIsValid(false);
                return;
            }
        } catch (ClientNotFoundException) {
            $this->logger->error('Token audience does not match any of our clients identifiers');
            $event->setIsValid(false);
            return;
        }

        // check user ID
        $userId = $decodedJwt['preferred_username'] ?? '';
        $user = $this->userManager->get($userId);
        if ($user === null) {
            $this->logger->error('Provided user in JWT is unknown.');
            $event->setIsValid(false);
            return;
        }

        // all good
        $event->setIsValid(true);
        $event->setUserId($userId);

    }
}
