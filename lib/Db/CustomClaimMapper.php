<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Service\CustomClaimService;
use Psr\Log\LoggerInterface;

/**
 * @template-extends QBMapper<CustomClaim>
 */
class CustomClaimMapper extends QBMapper {

    /** @var ClientMapper */
    private $clientMapper;
    /** @var LoggerInterface */
    private $logger;

    /**
     * @param IDBConnection $db
     */
    public function __construct(
        IDBConnection $db,
        ClientMapper $clientMapper,
        LoggerInterface $logger) {
        parent::__construct($db, 'oidc_custom_claims', CustomClaim::class);
        $this->clientMapper = $clientMapper;
        $this->logger = $logger;
    }

    /**
     * Find custom claims by client ID
     *
     * @param int $clientId
     * @return CustomClaim[]
     */
    public function findByClient(int $clientId): array {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * Find custom claims by client ID and scope
     *
     * @param int $clientId
     * @param string $scope
     * @return CustomClaim[]
     */
    public function findByClientAndScope(int $clientId, string $scope): array {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
            ->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * Find custom claim by client ID and name
     *
     * @param int $clientId
     * @param string $snme
     * @return CustomClaim|null
     */
    public function findByClientAndName(int $clientId, string $name): ?CustomClaim {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('name', $qb->createNamedParameter($name)))
            ->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find all custom claims
     *
     * @return CustomClaim[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->select('*')
            ->from($this->tableName);

        return $this->findEntities($qb);
    }

    public const FORBIDDEN_CLAIM_NAMES = [
        '', // prohibit empty names
        'iss', 'sub', 'aud', 'exp', 'nbf', 'iat', 'jti', // RFC 7519
        'cnf', 'crit', // RFC 8707
        'roles', 'groups', // used internally by the OIDC IdP app
        'at_hash', 'c_hash', 'auth_time', 'acr', 'amr', 'azp', 'scope', // used in OIDC standard
        'name', 'given_name', 'family_name', 'middle_name',
        'preferred_username', 'picture',
        'website',  'email', 'email_verified', 'updated_at', 'phone_number', 'address', 'quota', 'nonce',
    ];

    /**
     * Create or update a custom claim
     *
     * @param CustomClaim $customClaim
     * @return CustomClaim
     */
    public function createOrUpdate(CustomClaim $customClaim): CustomClaim {
        $existingClient = $this->clientMapper->getByUid($customClaim->getClientId()) ?? null;
        if ($existingClient === null) {
            throw new \InvalidArgumentException('Client ID '.$customClaim->getClientId().' does not exist');
        }
        // check for names length
        if (strlen($customClaim->getName()) > 255) {
            throw new \InvalidArgumentException('Claim name '.$customClaim->getName().' exceeds maximum length of 255 characters');
        }
        // check for forbidden names
        $normalized_name = strtolower(trim($customClaim->getName()));
        foreach (self::FORBIDDEN_CLAIM_NAMES as $value) {
            if ($normalized_name === $value) {
                throw new \InvalidArgumentException('Claim name '.$customClaim->getName().' is forbidden');
            }
        }
        // Check for valid characters in name
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $customClaim->getName())) {
            throw new \InvalidArgumentException('Claim name '.$customClaim->getName().' contains invalid characters. Only alphanumeric characters and underscore are allowed.');
        }
        // RFC 6749 allows most printable ASCII except space (used as separator), backslash, and double-quote
        // Commonly used characters: letters, numbers, underscore, hyphen, colon, period, forward slash
        if (!preg_match('/^[a-zA-Z0-9_:\.\/-]*$/u', $customClaim->getScope())) {
            throw new \InvalidArgumentException('Scope '.$customClaim->getScope().' contains invalid characters. RFC 6749 allows most printable ASCII except space (used as separator), backslash, and double-quote.');
        }
        // check for function
        $functionFound = false;
        foreach (CustomClaimService::FUNCTIONS as $function) {
            if ($function['name'] === $customClaim->getFunction()) {
                $functionFound = true;
                // function found, check parameters
                if (isset($function['parameters']) && is_array($function['parameters']) && count($function['parameters']) > 0) {
                    if ($customClaim->getParameter() == null) {
                        throw new \InvalidArgumentException('Function '.$customClaim->getFunction().' requires '.count($function['parameters']).' parameters, none given');
                    }
                    $arguments = explode(',', $customClaim->getParameter());
                    if (count($arguments) !== count($function['parameters'])) {
                        throw new \InvalidArgumentException('Function '.$customClaim->getFunction().' requires '.count($function['parameters']).' parameters, '.count($arguments).' given');
                    }
                }
                // function and parameters are valid
                break;
            }
        }
        if (!$functionFound) {
            throw new \InvalidArgumentException('Function '.$customClaim->getFunction().' is not valid');
        }
        // check for scope length
        if (strlen($customClaim->getScope()) > 255) {
            throw new \InvalidArgumentException('Scope '.$customClaim->getScope().' exceeds maximum length of 255 characters');
        }

        $existing = $this->findByClientAndName($customClaim->getClientId(), $customClaim->getName());

        if ($existing !== null) {
            // Update existing customClaim
            $existing->setScope($customClaim->getScope());
            $existing->setFunction($customClaim->getFunction());
            $existing->setParameter($customClaim->getParameter());
            return $this->update($existing);
        } else {
            // Insert new customClaim
            return $this->insert($customClaim);
        }
    }

    /**
     * Delete custom claim by name and client ID
     *
     * @param int $clientId
     * @param string $name
     */
    public function deleteByClientAndName(int $clientId, string $name): void {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->tableName)
            ->where($qb->expr()->eq('name', $qb->createNamedParameter($name)))
            ->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Delete all custom claim for a given client (cascade on client deletion)
     *
     * @param int $clientId
     */
    public function deleteByClientId(int $clientId): void {
        $qb = $this->db->getQueryBuilder();
        $qb
            ->delete($this->tableName)
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
