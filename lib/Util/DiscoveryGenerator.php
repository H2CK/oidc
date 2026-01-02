<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Util;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\AppFramework\Services\IAppConfig;
use Psr\Log\LoggerInterface;

class DiscoveryGenerator
{
    /** @var ITimeFactory */
    private $time;
    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var IAppConfig */
    private $appConfig;
    /** @var LoggerInterface */
    private $logger;
    /** @var ClientMapper */
    private $clientMapper;

    public function __construct(
                    ITimeFactory $time,
                    IURLGenerator $urlGenerator,
                    IAppConfig $appConfig,
                    LoggerInterface $logger,
                    ClientMapper $clientMapper
    ) {
        $this->time = $time;
        $this->urlGenerator = $urlGenerator;
        $this->appConfig = $appConfig;
        $this->logger = $logger;
        $this->clientMapper = $clientMapper;
    }

    /**
     * Aggregates scopes from all registered OAuth clients
     *
     * @return array Deduplicated list of scopes from all clients
     */
    private function getAggregatedScopes(): array
    {
        $aggregatedScopes = [];

        try {
            $clients = $this->clientMapper->getClients();

            foreach ($clients as $client) {
                $allowedScopes = trim($client->getAllowedScopes());

                // Skip clients with no allowed_scopes configured
                if ($allowedScopes === '') {
                    continue;
                }

                // Parse space-separated scopes
                $scopesArr = explode(' ', strtolower($allowedScopes));

                // Add to aggregated list (array_merge will handle duplicates via array_unique later)
                $aggregatedScopes = array_merge($aggregatedScopes, $scopesArr);
            }

            // Remove duplicates and empty values
            $aggregatedScopes = array_filter(array_unique($aggregatedScopes), function($scope) {
                return trim($scope) !== '';
            });

        } catch (\Exception $e) {
            $this->logger->warning('Failed to aggregate scopes from OAuth clients: ' . $e->getMessage());
        }

        return array_values($aggregatedScopes); // Re-index array
    }

    /**
     * Generates the responsefor the discovery endpoint
     *
     * @return JSONResponse
     */
    public function generateDiscovery(IRequest $request): JSONResponse
    {
        $host = $request->getServerProtocol() . '://' . $request->getServerHost();
        $issuer = $host . $this->urlGenerator->getWebroot();

        // Default OIDC scopes
        $defaultScopes = [
            'openid',
            'profile',
            'email',
            'roles',
            'groups',
            'offline_access',
        ];

        // Aggregate custom scopes from all registered OAuth clients
        $customScopes = $this->getAggregatedScopes();

        // Merge default and custom scopes, removing duplicates
        $scopesSupported = array_values(array_unique(array_merge($defaultScopes, $customScopes)));
        $responseTypesSupported = [
            'code',
            'code id_token',
            // 'code token',
            // 'code id_token token',
            'id_token',
            // 'id_token token'
        ];
        $responseModesSupported = [
            'query',
            // 'fragment',
        ];
        $grantTypesSupported = [
            'authorization_code',
            'implicit',
        ];
        $acrValuesSupported = [
            '0',
        ];
        $subjectTypesSupported = [
            // 'pairwise',
            'public',
        ];
        $idTokenSigningAlgValuesSupported = [
            'RS256',
            'HS256',
        ];
        // $userinfoSigningAlgValuesSupported = [
        //     'none',
        // ];
        $tokenEndpointAuthMethodsSupported = [
            'client_secret_post',
            'client_secret_basic',
            // 'client_secret_jwt',
            // 'private_key_jwt',
        ];
        $displayValuesSupported = [
            'page',
            // 'popup',
            // 'touch',
            // 'wap',
        ];
        $claimTypesSupported = [
            'normal',
            // 'aggregated',
            // 'distributed',
        ];
        $claimsSupported = [
            'iss',
            'sub',
            'aud',
            'exp',
            'auth_time',
            'iat',
            'acr',
            'azp',
            'preferred_username',
            'scope',
            'nbf',
            'jti',
            'roles',
            'groups',
            'name',
            'given_name',
            'family_name',
            'middle_name',
            'updated_at',
            'website',
            'email',
            'email_verified',
            'website',
            'phone_number',
            'address',
            'picture',
            'quota',
        ];

        $discoveryPayload = [
            'issuer' => $issuer,
            'authorization_endpoint' => $host . $this->urlGenerator->linkToRoute('oidc.LoginRedirector.authorize', []),
            'token_endpoint' => $host . $this->urlGenerator->linkToRoute('oidc.OIDCApi.getToken', []),
            'userinfo_endpoint' => $host . $this->urlGenerator->linkToRoute('oidc.UserInfo.getInfo', []),
            'jwks_uri' => $host . $this->urlGenerator->linkToRoute('oidc.Jwks.getKeyInfo', []),
            'scopes_supported' => $scopesSupported,
            'response_types_supported' => $responseTypesSupported,
            'response_modes_supported' => $responseModesSupported,
            'grant_types_supported' => $grantTypesSupported,
            'acr_values_supported' => $acrValuesSupported,
            'subject_types_supported' => $subjectTypesSupported,
            'id_token_signing_alg_values_supported' => $idTokenSigningAlgValuesSupported,
            // 'id_token_encryption_alg_values_supported' => ,
            // 'id_token_encryption_enc_values_supported' => ,
            // 'userinfo_signing_alg_values_supported' => $userinfoSigningAlgValuesSupported,
            // 'userinfo_encryption_alg_values_supported' => ,
            // 'userinfo_encryption_enc_values_supported' => ,
            // 'request_object_signing_alg_values_supported' => ,
            // 'request_object_encryption_alg_values_supported' => ,
            // 'request_object_encryption_enc_values_supported' => ,
            'token_endpoint_auth_methods_supported' => $tokenEndpointAuthMethodsSupported,
            // 'token_endpoint_auth_signing_alg_values_supported' => ,
            'display_values_supported' => $displayValuesSupported,
            'claim_types_supported' => $claimTypesSupported,
            'claims_supported' => $claimsSupported,
            // 'service_documentation' => ,
            // 'claims_locales_supported' => ,
            // 'ui_locales_supported' => ,
            // 'claims_parameter_supported' => true,
            // 'request_parameter_supported' => true,
            // 'request_uri_parameter_supported' => false,
            // 'require_request_uri_registration' => true,
            // 'op_policy_uri' => ,
            // 'op_tos_uri' => ,
            'end_session_endpoint' => $host . $this->urlGenerator->linkToRoute('oidc.Logout.logout', []),
        ];

        if ($this->appConfig->getAppValueString(Application::APP_CONFIG_DYNAMIC_CLIENT_REGISTRATION) == 'true') {
            $discoveryPayload['registration_endpoint'] = $host . $this->urlGenerator->linkToRoute('oidc.DynamicRegistration.registerClient', []);
        }

        // Add token introspection endpoint
        $discoveryPayload['introspection_endpoint'] = $host . $this->urlGenerator->linkToRoute('oidc.Introspection.introspectToken', []);
        $discoveryPayload['introspection_endpoint_auth_methods_supported'] = [
            'client_secret_post',
            'client_secret_basic'
        ];

        // Add PKCE support to discovery endpoint
        $discoveryPayload['code_challenge_methods_supported'] = ['S256', 'plain'];

        $this->logger->info('Request to Discovery Endpoint.');

        $response = new JSONResponse($discoveryPayload);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET');

        return $response;
    }

}
