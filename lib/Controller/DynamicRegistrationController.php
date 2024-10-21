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

use OC\Security\Bruteforce\Throttler;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\AppFramework\Services\IAppConfig;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCP\Security\ISecureRandom;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use Psr\Log\LoggerInterface;

class DynamicRegistrationController extends ApiController
{
    /** @var ClientMapper */
    private $clientMapper;
    /** @var ISecureRandom */
    private $secureRandom;
    /** @var AccessTokenMapper  */
    private $accessTokenMapper;
    /** @var RedirectUriMapper  */
    private $redirectUriMapper;
    /** @var LogoutRedirectUriMapper  */
    private $logoutRedirectUriMapper;
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

    public const VALID_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    public const NAME_PREFIX = 'DCR-';

    public function __construct(
                    string $appName,
                    IRequest $request,
                    ClientMapper $clientMapper,
                    ISecureRandom $secureRandom,
                    AccessTokenMapper $accessTokenMapper,
                    RedirectUriMapper $redirectUriMapper,
                    LogoutRedirectUriMapper $logoutRedirectUriMapper,
                    ITimeFactory $time,
                    Throttler $throttler,
                    IURLGenerator $urlGenerator,
                    IAppConfig $appConfig,
                    LoggerInterface $logger
                    )
    {
        parent::__construct($appName, $request);
        $this->secureRandom = $secureRandom;
        $this->clientMapper = $clientMapper;
        $this->accessTokenMapper = $accessTokenMapper;
        $this->redirectUriMapper = $redirectUriMapper;
        $this->logoutRedirectUriMapper = $logoutRedirectUriMapper;
        $this->time = $time;
        $this->throttler = $throttler;
        $this->urlGenerator = $urlGenerator;
        $this->appConfig = $appConfig;
        $this->logger = $logger;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=oidc_dcr)
     * @AnonRateThrottle(limit=10, period=60)
     *
     * @return JSONResponse
     */
    #[AnonRateLimit(limit: 10, period: 60)]
    #[BruteForceProtection(action: 'oidc_dcr')]
    #[NoCSRFRequired]
    #[PublicPage]
    public function registerClient(
        array $redirect_uris = null,
        string $client_name = null,
        string $id_token_signed_response_alg = 'RS256',
        array $response_types = ['code'],
        string $application_type = 'web',
        ): JSONResponse
    {
        if ($this->appConfig->getAppValue('dynamic_client_registration', 'false') != 'true') {
            $this->logger->info('Access to register dynamic client, but functionality disabled.');
            return new JSONResponse([
                'error' => 'dynamic_registration_not_allowed',
                'error_description' => 'Dynamic Client Registration is disabled.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($redirect_uris == null) {
            $this->logger->info('No redirect uris provided during register dynamic client.');
            return new JSONResponse([
                'error' => 'no_redirect_uris_provided',
                'error_description' => 'Dynamic Client Registration requires redirect_uris to be set.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (!is_array($redirect_uris)) {
            $this->logger->info('No redirect uris array delivered.');
            return new JSONResponse([
                'error' => 'no_redirect_uris_provided',
                'error_description' => 'Dynamic Client Registration requires redirect_uris to be set.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (empty($redirect_uris)) {
            $this->logger->info('No redirect uris array delivered.');
            return new JSONResponse([
                'error' => 'no_redirect_uris_provided',
                'error_description' => 'Dynamic Client Registration requires at least one redirect_uris to be set.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($application_type == 'native') {
            $application_type = 'native';
        } else {
            $application_type = 'web';
        }

        $this->clientMapper->cleanUp();

        if ($this->clientMapper->getNumDcrClients() > 100) {
            $this->logger->info('Maximum number of dynamic registered clients exceeded.');
            return new JSONResponse([
                'error' => 'max_num_clients_exceeded',
                'error_description' => 'Maximum number of dynamic registered clients exceeded.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $name = self::NAME_PREFIX . $this->getClientIp();
        if ($client_name != null) {
            $name = substr($client_name, 0, 64);
        }

        $client = new Client(
            $name,
            $redirect_uris,
            $id_token_signed_response_alg,
        );

        $client->setDcr(true);
        $response_types_arr = array();
        array_push($response_types_arr, 'code');
        $grant_types_arr = array();
        array_push($grant_types_arr, 'authorization_code');
        if (in_array('code', $response_types)) {
            $client->setFlowType('code');
        } elseif (in_array('id_token', $response_types)){
            $client->setFlowType('code id_token');
            array_push($response_types_arr, 'id_token');
            array_push($grant_types_arr, 'implicit');
        }

        $client = $this->clientMapper->insert($client);

        $jsonResponse = [
            'client_name' => $client->getName(),
            'client_id' => $client->getClientIdentifier(),
            'client_secret' => $client->getSecret(),
            'redirect_uris' => $redirect_uris,
            'token_endpoint_auth_method' => 'client_secret_post', // Force to use client secret post
            'response_types' => $response_types_arr,
            'grant_types' => $grant_types_arr,
            'id_token_signed_response_alg' => $client->getSigningAlg(),
            'application_type' => $application_type,
            'client_id_issued_at' => $client->getIssuedAt(),
            'client_secret_expires_at' => $client->getIssuedAt() + $this->appConfig->getAppValue('client_expire_time', '3600')
        ];

        $response = new JSONResponse($jsonResponse, Http::STATUS_CREATED);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'POST');

        return $response;
    }

    private function getClientIp() {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? $_SERVER['HTTP_CLIENT_IP']
            ?? $this->secureRandom->generate(64, self::VALID_CHARS);
    }

}
