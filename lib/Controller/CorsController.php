<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OC\Security\Bruteforce\Throttler;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class CorsController extends ApiController
{
    /** @var Throttler */
    private $throttler;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
                    string $appName,
                    IRequest $request,
                    Throttler $throttler,
                    LoggerInterface $logger
                    )
    {
        parent::__construct($appName, $request);
        $this->throttler = $throttler;
        $this->logger = $logger;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return Response
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function discoveryCorsResponse(): Response {
        return $this->corsResponse();
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return Response
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function jwksCorsResponse(): Response {
        return $this->corsResponse();
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return Response
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function userInfoCorsResponse(): Response {
        return $this->corsResponse();
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return Response
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function logoutCorsResponse(): Response {
        return $this->corsResponse();
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return Response
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function tokenCorsResponse(): Response {
        return $this->corsResponse();
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return Response
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function authorizeCorsResponse(): Response {
        return $this->corsResponse();
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return Response
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function registerCorsResponse(): Response {
        return $this->corsResponse();
    }


    private function corsResponse(): Response {
        $response = new Response();
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'PUT, POST, GET, DELETE, PATCH');
        return $response;
    }

}
