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
namespace OCA\OIDCIdentityProvider\Controller;

use OCA\OIDCIdentityProvider\Util\DiscoveryGenerator;
use OC\Security\Bruteforce\Throttler;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
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
	 * @NoCSRFRequired
     *
     * Must be proviced at path:
     * <issuer>//.well-known/openid-configuration
	 *
	 * @return JSONResponse
	 */
	public function getInfo(): JSONResponse
	{
		return $this->discoveryGenerator->generateDiscovery($this->request);
	}

}
