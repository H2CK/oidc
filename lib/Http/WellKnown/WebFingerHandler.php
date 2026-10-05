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
use OCP\IUserManager;

class WebFingerHandler implements IHandler {
    private IURLGenerator $urlGenerator;

    public function __construct(
        IURLGenerator $urlGenerator,
        private IUserManager $userManager,
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

        $request = $context->getHttpRequest();
        $subject = $request->getParam('resource');
        if (!is_string($subject) || $subject === '' || strlen($subject) > 2000) {
            return $previousResponse;
        }
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $subject) === 1) {
            return $previousResponse;
        }
        $issuer = $request->getServerProtocol()
            . '://'
            . $request->getServerHost()
            . $this->urlGenerator->getWebroot();

        $relation = 'http://openid.net/specs/connect/1.0/issuer';
        $requestedRelations = $request->getParam('rel');
        if ($requestedRelations !== null
            && !in_array($relation, (array)$requestedRelations, true)) {
            return $previousResponse;
        }
        // Serve only the issuer itself or known local acct identifiers. Leave
        // unrelated resources to other handlers (or Nextcloud's 404 fallback).
        if ($subject !== $issuer) {
            if (!preg_match('/\Aacct:([^@\s]+)@([^@\s]+)\z/D', $subject, $matches)
                || strcasecmp($matches[2], $request->getServerHost()) !== 0) {
                return $previousResponse;
            }
            $userId = rawurldecode($matches[1]);
            if (preg_match('/[\x00-\x1f\x7f]/', $userId) === 1 || $this->userManager->get($userId) === null) {
                return $previousResponse;
            }
        }

        // RFC 7033: preserve the resource URI, including its acct: scheme.
        $response = new JrdResponse($subject);

        $response->addLink(
            $relation,
            null,
            $issuer,
            null,
            null,
            []
        );

        return $response;
    }

}
