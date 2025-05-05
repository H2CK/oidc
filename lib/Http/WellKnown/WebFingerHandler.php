<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2025 Thorsten Jagel <dev@jagel.net>
 *
 * @author Thorsten Jagel <dev@jagel.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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
