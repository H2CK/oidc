<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\CustomClaim;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IConfig;
use OCP\AppFramework\Services\IAppConfig;
use Psr\Log\LoggerInterface;

class CustomClaimController extends Controller
{
    /** @var ClientMapper */
    private $clientMapper;
    /** @var CustomClaimMapper  */
    private $customClaimMapper;
    /** @var IL10N */
    private $l;
    /** @var IUserSession */
    private $userSession;
    /** @var IAppConfig */
    private $appConfig;
    /** @var IConfig */
    private $config;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
                    string $appName,
                    IRequest $request,
                    ClientMapper $clientMapper,
                    CustomClaimMapper $customClaimMapper,
                    IL10N $l,
                    IUserSession $userSession,
                    IAppConfig $appConfig,
                    IConfig $config,
                    LoggerInterface $logger
                    )
    {
        parent::__construct($appName, $request);
        $this->clientMapper = $clientMapper;
        $this->customClaimMapper = $customClaimMapper;
        $this->l = $l;
        $this->userSession =$userSession;
        $this->appConfig = $appConfig;
        $this->config =$config;
        $this->logger = $logger;
    }

    public function addCustomClaim(
                    string $name,
                    string $scope,
                    string $function,
                    string|null $parameter = null,
                    string|null $clientId = null,
                    int|null $clientUid = null
                    ): JSONResponse
    {
        if ($clientId == null && $clientUid == null) {
            $this->logger->warning("Adding custom claim " . $name. " failed. Missing client identifier.");
            return new JSONResponse(['message' => $this->l->t('Client Identifier is missing in the request')], Http::STATUS_BAD_REQUEST);
        }
        try {
            if ($clientId == null) {
                $client = $this->clientMapper->getByUid($clientUid);
                $clientId = $client->getClientIdentifier();
            }
            if ($clientUid == null) {
                $client = $this->clientMapper->getByIdentifier($clientId);
                $clientUid = $client->getId();
            }
        } catch (\Exception $e) {
            $this->logger->warning("Adding custom claim " . $name. " failed. Could not find client for the given uid or client identifier.");
            return new JSONResponse(['message' => $this->l->t('Could not find client for the given uid or client identifier')], Http::STATUS_BAD_REQUEST);
        }

        $this->logger->debug("Adding custom claim " . $name. " for client " .$clientId);

        $customClaim = new CustomClaim();
        $customClaim->setClientId($clientUid);
        $customClaim->setName($name);
        $customClaim->setScope($scope);
        $customClaim->setFunction($function);
        $customClaim->setParameter($parameter);
        try {
            $customClaim = $this->customClaimMapper->createOrUpdate($customClaim);
            return new JSONResponse([
                'id' => $customClaim->getId(),
                'clientId' => $clientUid,
                'clientIdentifier' => $clientId,
                'name' => $customClaim->getName(),
                'scope' => $customClaim->getScope(),
                'function' => $customClaim->getFunction(),
                'parameter' => $customClaim->getParameter(),
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    public function updateCustomClaim(
                    string $name,
                    string $scope,
                    string $function,
                    string|null $parameter = null,
                    string|null $clientId = null,
                    int|null $clientUid = null,
                    int|null $id = null,
                    ): JSONResponse
    {
        if ($clientId == null && $clientUid == null) {
            $this->logger->warning("Updating custom claim " . $name. " failed. Missing client identifier.");
            return new JSONResponse(['message' => $this->l->t('Client Identifier is missing in the request')], Http::STATUS_BAD_REQUEST);
        }
        try {
            if ($clientId == null) {
                $client = $this->clientMapper->getByUid($clientUid);
                $clientId = $client->getClientIdentifier();
            }
            if ($clientUid == null) {
                $client = $this->clientMapper->getByIdentifier($clientId);
                $clientUid = $client->getId();
            }
        } catch (\Exception $e) {
            $this->logger->warning("Updating custom claim " . $name. " failed. Could not find client for the given uid or client identifier.");
            return new JSONResponse(['message' => $this->l->t('Could not find client for the given uid or client identifier')], Http::STATUS_BAD_REQUEST);
        }

        $this->logger->debug("Updating custom claim " . $name. " for client " .$clientId);

        $customClaim = new CustomClaim();
        $customClaim->setClientId($clientUid);
        $customClaim->setName($name);
        $customClaim->setScope($scope);
        $customClaim->setFunction($function);
        $customClaim->setParameter($parameter);
        try {
            $customClaim = $this->customClaimMapper->createOrUpdate($customClaim);
            if ($id != null && $id !== $customClaim->getId()) {
                $this->logger->warning("Updating custom claim " . $name. " done. But received custom claim id does not correspond to db entry. Correct id is " . $customClaim->getId());
            }
            return new JSONResponse([
                'id' => $customClaim->getId(),
                'clientId' => $clientUid,
                'clientIdentifier' => $clientId,
                'name' => $customClaim->getName(),
                'scope' => $customClaim->getScope(),
                'function' => $customClaim->getFunction(),
                'parameter' => $customClaim->getParameter(),
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    public function deleteCustomClaim(
                    string|null $name = null,
                    string|null $clientId = null,
                    int|null $clientUid = null,
                    int|null $id = null,
                    ): JSONResponse
    {
        if ($id != null) {
            try {
                $customClaim = $this->customClaimMapper->findById($id);
                $this->customClaimMapper->delete($customClaim);
                return new JSONResponse([]);
            } catch (\Exception $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
        } else {
            if ($clientId == null && $clientUid == null) {
                $this->logger->warning("Deleting custom claim " . $name. " failed. Missing client identifier.");
                return new JSONResponse(['message' => $this->l->t('Client Identifier is missing in the request')], Http::STATUS_BAD_REQUEST);
            }
            try {
                if ($clientId == null) {
                    $client = $this->clientMapper->getByUid($clientUid);
                    $clientId = $client->getClientIdentifier();
                }
                if ($clientUid == null) {
                    $client = $this->clientMapper->getByIdentifier($clientId);
                    $clientUid = $client->getId();
                }
            } catch (\Exception $e) {
                $this->logger->warning("Deleting custom claim " . $name. " failed. Could not find client for the given uid or client identifier.");
                return new JSONResponse(['message' => $this->l->t('Could not find client for the given uid or client identifier')], Http::STATUS_BAD_REQUEST);
            }
            if (empty($name)) {
                $this->logger->warning("Deleting custom claim " . $name. " failed. Missing custom claim name.");
                return new JSONResponse(['message' => $this->l->t('Custom claim name is missing in the request')], Http::STATUS_BAD_REQUEST);
            }

            $this->logger->debug("Deleting custom claim " . $name. " for client " .$clientId);

            try {
                $this->customClaimMapper->deleteByClientAndName($clientUid, $name);
                return new JSONResponse([]);
            } catch (\Exception $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
        }
    }

    public function getCustomClaim(
                    string|null $name = null,
                    string|null $clientId = null,
                    int|null $clientUid = null,
                    int|null $id = null,
                    ): JSONResponse
    {
        if ($id != null) {
            try {
                $customClaim = $this->customClaimMapper->findById($id);
                $client = $this->clientMapper->getByUid($customClaim->getClientId());
                return new JSONResponse([
                    'id' => $customClaim->getId(),
                    'clientId' => $customClaim->getClientId(),
                    'clientIdentifier' => $client->getClientIdentifier(),
                    'name' => $customClaim->getName(),
                    'scope' => $customClaim->getScope(),
                    'function' => $customClaim->getFunction(),
                    'parameter' => $customClaim->getParameter(),
                ]);
            } catch (\Exception $e) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
        } else {
            if ($clientId != null || $clientUid != null) {
                try {
                    if ($clientId == null) {
                        $client = $this->clientMapper->getByUid($clientUid);
                        $clientId = $client->getClientIdentifier();
                    }
                    if ($clientUid == null) {
                        $client = $this->clientMapper->getByIdentifier($clientId);
                        $clientUid = $client->getId();
                    }
                } catch (\Exception $e) {
                    $this->logger->warning("Providing custom claim " . $name. " failed. Could not find client for the given uid or client identifier.");
                    return new JSONResponse(['message' => $this->l->t('Could not find client for the given uid or client identifier')], Http::STATUS_BAD_REQUEST);
                }
                if (empty($name)) {
                    $this->logger->warning("Providing custom claim " . $name. " failed. Missing custom claim name.");
                    return new JSONResponse(['message' => $this->l->t('Custom claim name is missing in the request')], Http::STATUS_BAD_REQUEST);
                }
                try {
                    $customClaim = $this->customClaimMapper->findByClientAndName($clientUid, $name);
                    return new JSONResponse([
                        'id' => $customClaim->getId(),
                        'clientId' => $clientUid,
                        'clientIdentifier' => $clientId,
                        'name' => $customClaim->getName(),
                        'scope' => $customClaim->getScope(),
                        'function' => $customClaim->getFunction(),
                        'parameter' => $customClaim->getParameter(),
                    ]);
                } catch (\Exception $e) {
                    return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
                }
            }
        }
        try {
            $customClaims = $this->customClaimMapper->findAll();
            $response = [];
            foreach ($customClaims as $customClaim) {
                $client = $this->clientMapper->getByUid($customClaim->getClientId());
                $response[] = [
                    'id' => $customClaim->getId(),
                    'clientId' => $customClaim->getClientId(),
                    'clientIdentifier' => $client->getClientIdentifier(),
                    'name' => $customClaim->getName(),
                    'scope' => $customClaim->getScope(),
                    'function' => $customClaim->getFunction(),
                    'parameter' => $customClaim->getParameter(),
                ];
            }
            return new JSONResponse($response);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

}
