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
use OCP\Http\WellKnown\JrdResponse;
use OCP\IURLGenerator;

class WebFingerHandler implements IHandler {
    private IURLGenerator $urlGenerator;

    public function __construct(
        IURLGenerator $urlGenerator
    ) {
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * WebFingerHandler for OIDC request
     * @see https://docs.joinmastodon.org/spec/webfinger/
     *
     * @param string $service
     * @param IRequestContext $context
     * @param IResponse|null $previousResponse
     *
     * @return IResponse|null
     */
    public function handle(
        string $service,
        IRequestContext $context,
        ?IResponse $previousResponse
    ): ?IResponse {
        if ($service !== 'webfinger') {
            // Not relevant to this handler
            return $previousResponse;
        }

        $subject = $context->getHttpRequest()->getParam('resource') ?? '';
        if (strpos($subject, 'acct:') === 0) {
            $subject = substr($subject, 5);
        }

        $issuer = $context->getHttpRequest()->getServerProtocol()
            . '://'
            . $context->getHttpRequest()->getServerHost()
            . $this->urlGenerator->getWebroot();

        $response = new JrdResponse($subject);

        $response->addLink(
            'http://openid.net/specs/connect/1.0/issuer',
            null,
            $issuer,
            null,
            null,
            []
        );

        return $response;
    }

}
