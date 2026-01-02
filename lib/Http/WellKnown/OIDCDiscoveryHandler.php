<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Http\WellKnown;

use OCP\AppFramework\Http;
use OCP\Http\WellKnown\IHandler;
use OCP\Http\WellKnown\IRequestContext;
use OCP\Http\WellKnown\IResponse;
use OCA\OIDCIdentityProvider\Util\DiscoveryGenerator;
use OCA\OIDCIdentityProvider\Http\WellKnown\JsonResponseMapper;
use OCP\AppFramework\Http\Attribute\CORS;

class OIDCDiscoveryHandler implements IHandler {
    /** @var DiscoveryGenerator */
    private $discoveryGenerator;

    public function __construct(
        DiscoveryGenerator $discoveryGenerator
    ) {
        $this->discoveryGenerator = $discoveryGenerator;
    }

    /**
     * Handle well-known request for openid-configuration
     * @param string $service
     * @param IRequestContext $context
     * @param IResponse|null $previousResponse
     *
     * @return IResponse|null
     */
    #[CORS]
    public function handle(
        string $service,
        IRequestContext $context,
        ?IResponse $previousResponse
    ): ?IResponse {
        if ($service !== 'openid-configuration') {
            // Not relevant to this handler
            return $previousResponse;
        }

        return new JsonResponseMapper($this->discoveryGenerator->generateDiscovery($context->getHttpRequest()));
    }

}
