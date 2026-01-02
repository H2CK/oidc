<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OC\Security\Bruteforce\Throttler;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use Psr\Log\LoggerInterface;

class JwksController extends ApiController
{
    /** @var ITimeFactory */
    private $time;
    /** @var Throttler */
    private $throttler;
    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var IAppConfig */
    private $appConfig;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
                    string $appName,
                    IRequest $request,
                    ITimeFactory $time,
                    Throttler $throttler,
                    IURLGenerator $urlGenerator,
                    IAppConfig $appConfig,
                    LoggerInterface $logger
                    )
    {
        parent::__construct($appName, $request);
        $this->time = $time;
        $this->throttler = $throttler;
        $this->urlGenerator = $urlGenerator;
        $this->appConfig = $appConfig;
        $this->logger = $logger;
    }

    /**
     * @PublicPage
     * @CORS
     * @NoCSRFRequired
     * @BruteForceProtection(action=oidc_jwks)
     * @AnonRateThrottle(limit=1500, period=540)
     *
     * @return JSONResponse
     */
    #[AnonRateLimit(limit: 1500, period: 540)]
    #[BruteForceProtection(action: 'oidc_jwks')]
    #[NoCSRFRequired]
    #[CORS]
    #[PublicPage]
    public function getKeyInfo(): JSONResponse
    {
        $keyOps = [
            // 'sign',       // (compute digital signature or MAC)
            'verify',     // (verify digital signature or MAC)
            // 'encrypt',    // (encrypt content)
            // 'decrypt',    // (decrypt content and validate decryption, if applicable)
            // 'wrapKey',    // (encrypt key)
            // 'unwrapKey',  // (decrypt key and validate decryption, if applicable)
            // 'deriveKey',  // (derive key)
            // 'deriveBits', // (derive bits not to be used as a key)
        ];

        $oidcKey = [
            'kty' => 'RSA',
            'use' => 'sig',
            'key_ops' => $keyOps,
            'alg' => 'RS256',
            'kid' => $this->appConfig->getAppValueString('kid'),
            'n' => $this->appConfig->getAppValueString('public_key_n'),
            'e' => $this->appConfig->getAppValueString('public_key_e'),
        ];

        $keys = [
            $oidcKey
        ];

        $jwkPayload = [
            'keys' => $keys,
        ];

        $this->logger->info('Request to JWKS Endpoint.');

        $response = new JSONResponse($jwkPayload);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET');

        return $response;
    }


}
