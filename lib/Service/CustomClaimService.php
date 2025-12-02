<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\CustomClaim;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;
use OCP\IUser;
use OCP\IGroup;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

class CustomClaimService {
    public const FUNCTIONS = [
        [ 'name' => 'isAdmin', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::isAdmin', 'parameters' => [] ],
        [ 'name' => 'hasRole', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::hasRole', 'parameters' => ['role'] ],
        [ 'name' => 'isInGroup', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::hasRole', 'parameters' => ['group'] ],
        [ 'name' => 'getUserEmail', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::getUserEmail', 'parameters' => [] ],
        [ 'name' => 'getUserGroups', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::getUserGroups', 'parameters' => [] ],
		[ 'name' => 'getUserGroupsDisplayName', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::getUserGroupsDisplayName', 'parameters' => [] ]
    ];

    /** @var CustomClaimMapper */
    private $customClaimMapper;
    /** @var IUserManager */
    private $userManager;
    /** @var IGroupManager */
    private $groupManager;
    /** @var IAccountManager */
    private $accountManager;
    /** @var LoggerInterface */
    private $logger;

    /**
     * @param CustomClaimMapper $customClaimMapper
     * @param IUserManager $userManager
     * @param IGroupManager $groupManager
     * @param IAccountManager $accountManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        CustomClaimMapper $customClaimMapper,
        IUserManager $userManager,
        IGroupManager $groupManager,
        IAccountManager $accountManager,
        LoggerInterface $logger
    ) {
        $this->customClaimMapper = $customClaimMapper;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->accountManager = $accountManager;
        $this->logger = $logger;
    }

    /**
     * Provide custom claims for a given client ID
     *
     * @param int $clientId The client ID
     * @param string $scopes The scopes requested
     * @param string $uid The user ID
     */
    public function provideCustomClaims(int $clientId, string $scopes, string $uid): array {
        $user = $this->userManager->get($uid);
        $customClaimsObjArray = $this->customClaimMapper->findByClient($clientId);
        $customClaimsArray = [];
        if (!$customClaimsObjArray) {
            return $customClaimsArray;
        } else {
            foreach ($customClaimsObjArray as $claim) {
                $function = null;
                foreach (self::FUNCTIONS as $func) {
                    if ($func['name'] === $claim->getFunction()) {
                        $function = $func;
                        break;
                    }
                }
                if ($function === null) {
                    continue;
                }
                // Check if the claim's scope is included in the requested scopes
                $scopeList = explode(' ', $scopes);
                if (!in_array($claim->getScope(), $scopeList)) {
                    continue;
                }
                $this->logger->debug('Processing custom claim: ' . $claim->getName(). ' in scope ' . $claim->getScope());
                $functionResult = null;
                $arguments = [];
                if (isset($function['parameters']) && is_array($function['parameters']) && count($function['parameters']) > 0) {
                    $args = explode(',', $claim->getParameter());
                    foreach ($args as $arg) {
                        $arg = trim($arg);
                        if ($arg !== '') {
                            $arguments[] = $arg;
                        }
                    }
                }
                switch ($claim->getFunction()) {
                    case 'isAdmin':
                        $functionResult = $this->isAdmin($user);
                        break;
                    case 'hasRole':
                    case 'isInGroup':
                        $functionResult = $this->hasRole($user, $arguments[0] ?? '');
                        break;
                    case 'getUserEmail':
                        $functionResult = $this->getUserEmail($user);
                        break;
                    case 'getUserGroups':
                        $functionResult = $this->getUserGroups($user);
                        break;
                    default:
                        $functionResult = null;
                }
                if ($functionResult !== null) {
                    $customClaimsArray[$claim->getName()] = $functionResult;
                }
            }
            $this->logger->debug('Result for custom claims: ' . json_encode($customClaimsArray));
            return $customClaimsArray;
        }
    }

    /**
     * Check if the current user is admin
     *
     * @return bool|null Returns true if the user is admin, false if not, null if no user is logged in
     */
    public function isAdmin(IUser $user): bool|null {
        if ($user === null) {
            return null;
        }
        return $this->groupManager->isAdmin($user->getUID());
    }

    /**
     * Check if the current user has a specific role
     *
     * @return bool|null Returns true if the user has the role, false if not, null if no user is logged in
     */
    public function hasRole(IUser $user, string $role): bool|null {
        if ($user === null) {
            return null;
        }
        return $this->groupManager->isInGroup($user->getUID(), $role);
    }

    /**
     * Summary of getGroups
     *
     * @return array|null
     */
    public function getUserGroups(IUser $user): array|null {
        if ($user === null) {
            return null;
        }
        $groups = $this->groupManager->getUserGroups($user);

        $groupNames = [];
        foreach ($groups as $group) {
            $groupNames[] = $group->getGID();
        }
        return $groupNames;
    }

    /**
     * Summary of getGroups
     *
     * @return array|null
     */
    public function getUserGroupsDisplayName(IUser $user): array|null {
        if ($user === null) {
            return null;
        }
        $groups = $this->groupManager->getUserGroups($user);

        $groupNames = [];
        foreach ($groups as $group) {
            $groupNames[] = $group->getDisplayName();
        }
        return $groupNames;
    }

    /**
     * Summary of getUserEmail
     *
     * @return string|null
     */
    public function getUserEmail(IUser $user): string|null {
        if ($user === null) {
            return null;
        }
        return $user->getEMailAddress();
    }

}
