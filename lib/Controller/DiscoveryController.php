<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OCA\OIDCIdentityProvider\Util\DiscoveryGenerator;
use OC\Security\Bruteforce\Throttler;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\CORS;
use Psr\Log\LoggerInterface;

class DiscoveryController extends ApiController
{
    /** @var ITimeFactory */
    private $time;
    /** @var Throttler */
    private $throttler;
    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var DiscoveryGenerator */
    private $discoveryGenerator;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
                    string $appName,
                    IRequest $request,
                    ITimeFactory $time,
                    Throttler $throttler,
                    IURLGenerator $urlGenerator,
                    DiscoveryGenerator $discoveryGenerator,
                    LoggerInterface $logger
                    )
    {
        parent::__construct($appName, $request);
        $this->time = $time;
        $this->throttler = $throttler;
        $this->urlGenerator = $urlGenerator;
        $this->discoveryGenerator = $discoveryGenerator;
        $this->logger = $logger;
    }

    /**
     * @PublicPage
     * @CORS
     * @NoCSRFRequired
     * @BruteForceProtection(action=oidc_discovery)
     * @AnonRateThrottle(limit=1500, period=540)
     *
     * Must be proviced at path:
     * <issuer>//.well-known/openid-configuration
     *
     * @return JSONResponse
     */
    #[AnonRateLimit(limit: 1500, period: 540)]
    #[BruteForceProtection(action: 'oidc_discovery')]
    #[NoCSRFRequired]
    #[PublicPage]
    #[CORS]
    public function getInfo(): JSONResponse
    {
        return $this->discoveryGenerator->generateDiscovery($this->request);
    }

}
