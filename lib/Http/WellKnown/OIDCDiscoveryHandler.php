<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2024 Thorsten Jagel <dev@jagel.net>
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
