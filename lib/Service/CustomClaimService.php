<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
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
use OCP\IConfig;
use OCP\Group\ISubAdmin;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use OCP\L10N\IFactory as L10nFactory;

class CustomClaimService {
    public const FUNCTIONS = [
        [ 'name' => 'isAdmin', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::isAdmin', 'parameters' => [] ],
        [ 'name' => 'isGroupAdmin', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::isGroupAdmin', 'parameters' => ['group'] ],
        [ 'name' => 'hasRole', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::hasRole', 'parameters' => ['role'] ],
        [ 'name' => 'isInGroup', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::hasRole', 'parameters' => ['group'] ],
        [ 'name' => 'getUserEmail', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::getUserEmail', 'parameters' => [] ],
        [ 'name' => 'getUserGroups', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::getUserGroups', 'parameters' => [] ],
        [ 'name' => 'getUserGroupsDisplayName', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::getUserGroupsDisplayName', 'parameters' => [] ],
        [ 'name' => 'getUserGroupsString', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::getUserGroupsStr', 'parameters' => [] ],
        [ 'name' => 'getUserGroupsDisplayNameString', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::getUserGroupsDisplayNameStr', 'parameters' => [] ],
        [ 'name' => 'getUserLanguage', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::getUserLanguage', 'parameters' => [] ],
        [ 'name' => 'getUserLocale', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::getUserLocale', 'parameters' => [] ],
        [ 'name' => 'getUserFDOW', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::getUserFDOW', 'parameters' => [] ],
        [ 'name' => 'getUserTimezone', 'method' => 'OCA\OIDCIdentityProvider\Service\CustomClaimService::getUserTimezone', 'parameters' => [] ]
    ];

    /** @var CustomClaimMapper */
    private $customClaimMapper;
    /** @var IUserManager */
    private $userManager;
    /** @var IGroupManager */
    private $groupManager;
    /** @var ISubAdmin */
    private $subAdminManager;
    /** @var IAccountManager */
    private $accountManager;
    /** @var LoggerInterface */
    private $logger;
    /** @var Config */
    private $config;
    /** @var $lFactory **/
    private $lFactory;

    /**
     * @param CustomClaimMapper $customClaimMapper
     * @param IUserManager $userManager
     * @param IGroupManager $groupManager
     * @param ISubAdmin $subAdminManager
     * @param IAccountManager $accountManager
     * @param LoggerInterface $logger
     * @param IConfig $config
     * @param L10nFactory $lFactory
     */
    public function __construct(
        CustomClaimMapper $customClaimMapper,
        IUserManager $userManager,
        IGroupManager $groupManager,
        ISubAdmin $subAdminManager,
        IAccountManager $accountManager,
        LoggerInterface $logger,
        IConfig $config,
        L10nFactory $lFactory
    ) {
        $this->customClaimMapper = $customClaimMapper;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->subAdminManager = $subAdminManager;
        $this->accountManager = $accountManager;
        $this->logger = $logger;
        $this->config = $config;
        $this->lFactory = $lFactory;
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
                    case 'isGroupAdmin':
                        $functionResult = $this->isGroupAdmin($user, $arguments[0] ?? '');
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
                    case 'getUserGroupsDisplayName':
                        $functionResult = $this->getUserGroupsDisplayName($user);
                        break;
                    case 'getUserGroupsString':
                        $functionResult = $this->getUserGroupsStr($user);
                        break;
                    case 'getUserGroupsDisplayNameString':
                        $functionResult = $this->getUserGroupsDisplayNameStr($user);
                        break;
                    case 'getUserLanguage':
                        $functionResult = $this->getUserLanguage($user);
                        break;
                    case 'getUserLocale':
                        $functionResult = $this->getUserLocale($user);
                        break;
                    case 'getUserFDOW':
                        $functionResult = $this->getUserFDOW($user);
                        break;
                    case 'getUserTimezone':
                        $functionResult = $this->getUserTimezone($user);
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
     * Check if the current user is a subadmin (group admin) of a specific group
     *
     * @param IUser $user The user to check
     * @param string $groupId The group ID to check
     * @return bool|null Returns true if the user is a group admin, false if not, null if user or group is invalid
     */
    public function isGroupAdmin(IUser $user, string $groupId): bool|null {
        if ($user === null) {
            return null;
        }
        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            return null;
        }
        return $this->subAdminManager->isSubAdminOfGroup($user, $group);
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
     * Returns the group names of the user as an array
     *
     * @param IUser $user
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
     * Returns the group names of the user as a coma sparated string
     *
     * @param IUser $user
     * @return string|null
     */
    public function getUserGroupsStr(IUser $user): string|null {
        if ($user === null) {
            return null;
        }
        $groups = $this->groupManager->getUserGroups($user);

        $groupNames = [];
        foreach ($groups as $group) {
            $groupNames[] = $group->getGID();
        }
        return implode(', ', $groupNames);
    }

    /**
     * Returns the group display names of the user as ann array
     *
     * @param IUser $user
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
     * Returns the group display names of the user as a coma sparated string
     *
     * @param IUser $user
     * @return string|null
     */
    public function getUserGroupsDisplayNameStr(IUser $user): string|null {
        if ($user === null) {
            return null;
        }
        $groups = $this->groupManager->getUserGroups($user);

        $groupNames = [];
        foreach ($groups as $group) {
            $groupNames[] = $group->getDisplayName();
        }
        return implode(', ', $groupNames);
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

    /**
     * @param IUser $user
     * @return string|null Return the language, that is used by the user or forced by system
     */
    public function getUserLanguage(IUser $user): string|null {
        if ($user === null) {
            return null;
        }
        return $this->lFactory->getUserLanguage($user);
    }

    /**
     * @param IUser $user
     * @return string|null Return the locale, that is used by the user or forced by system
     */
    public function getUserLocale(IUser $user): string|null {
        if ($user === null) {
            return null;
        }
        return $this->getUserCoreValue($user, 'locale') ?? $this->config->getSystemValue('default_locale', 'en');
    }

    /**
     * @param IUser $user
     * @return int|null Return the Users setting of used first day of week or use the locale setting; 0 = sunday, 1 = monday, ...
     */
    public function getUserFDOW(IUser $user): int|null {
        if ($user === null) {
            return null;
        }
        return $this->getUserCoreValue($user, 'first_day_of_week') ?? $this->lFactory->get('core', $this->getUserLocale($user) )->l('firstday', null);
    }

    /**
     * @param IUser $user
     * @return string|null Return the Users setting of used Timezone
     */
    public function getUserTimezone(IUser $user): string|null {
        if ($user === null) {
            return null;
        }
        return $this->getUserCoreValue($user, 'timezone') ?? $this->config->getSystemValue('default_timezone', 'UTC');;
    }

    /**
     * @param \DateTimeZone $timezone
     * @param string $key
     * @return string|null The Value of a users config from core settings
     */
    private function getUserCoreValue(IUser $user, string $key, $default=null): string|null {
        $userId = $user->getUID();

        return $this->config->getUserValue($userId, 'core', $key, $default);
    }
}
