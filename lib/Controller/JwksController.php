<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Thorsten Jagel <dev@jagel.net>
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

use OC\Security\Bruteforce\Throttler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\AppFramework\Services\IAppConfig;


class JwksController extends Controller {
	/** @var ITimeFactory */
	private $time;
	/** @var Throttler */
	private $throttler;
    /** @var IURLGenerator */
	private $urlGenerator;
    /** @var IAppConfig */
	private $appConfig;

	public function __construct(string $appName,
								IRequest $request,
								ITimeFactory $time,
								Throttler $throttler,
                                IURLGenerator $urlGenerator,
                                IAppConfig $appConfig) {
		parent::__construct($appName, $request);
		$this->time = $time;
		$this->throttler = $throttler;
        $this->urlGenerator = $urlGenerator;
        $this->appConfig = $appConfig;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
     * @CORS
     * 
     * Must be proviced at path:
     * <issuer>//.well-known/openid-configuration
	 *
	 * @return JSONResponse
	 */
	public function getKeyInfo(): JSONResponse {
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
        
        $use = [
            'sig',
            // 'enc',
        ];
        
        $oidcKey = [
            'kty' => 'RSA',
            'use' => $use,
            'key_ops' => $keyOps,
            'alg' => 'RS256',
            'kid' => $this->appConfig->getAppValue('kid'),
            'n' => $this->appConfig->getAppValue('public_key_n'),
            'e' => $this->appConfig->getAppValue('public_key_e'),
        ];
        
        $keys = [
            $oidcKey
        ];

		$jwkPayload = [
			'keys' => $keys,
		];

		return new JSONResponse($jwkPayload);
	}

    
}
