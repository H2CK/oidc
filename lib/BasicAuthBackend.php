<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider;

use OCP\IRequest;
use OCP\IURLGenerator;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * User Backend as workaround to allow Basic Auth for fetching token.
 *
 * @category Apps
 * @package  OIDCIdentityProvider
 * @author   Thorsten Jagel <dev@jagel.net>
 * @license  http://www.gnu.org/licenses/agpl AGPL
 */
class BasicAuthBackend extends \OC\User\Backend {

    /** @var IRequest */
    private $request;
    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var ClientMapper */
    private $clientMapper;
    /** @var LoggerInterface */
    private $logger;

    /**
     * @param IRequest $request
     * @param IURLGenerator $urlGenerator
     * @param ClientMapper $clientMapper
     * @param LoggerInterface $loggerInterface
     */
    public function __construct(
        IRequest $request,
        IURLGenerator $urlGenerator,
        ClientMapper $clientMapper,
        LoggerInterface $logger) {
        $this->request = $request;
        $this->urlGenerator = $urlGenerator;
        $this->clientMapper = $clientMapper;
        $this->logger = $logger;
    }


    /**
    * Check if backend implements actions
    * @param int $actions bitwise-or'ed actions
    * @return boolean
    *
    *    self::CREATE_USER => 'createUser',
    *    self::SET_PASSWORD => 'setPassword',
    *    self::CHECK_PASSWORD => 'checkPassword',
    *    self::GET_HOME => 'getHome',
    *    self::GET_DISPLAYNAME => 'getDisplayName',
    *    self::SET_DISPLAYNAME => 'setDisplayName',
    *    self::PROVIDE_AVATAR => 'canChangeAvatar',
    *    self::COUNT_USERS => 'countUsers'
    *
    * Returns the supported actions as int to be
    * compared with self::CREATE_USER etc.
    */
    public function implementsActions($actions) {
        return (bool)((\OC\User\Backend::CHECK_PASSWORD)
        & $actions);
    }

    /**
     * @brief Check if the password is correct
     * @param $uid The client_id for OIDC
     * @param $password The client_credentials for OIDC
     * @returns true/false
     *
     * Check if the password is correct without logging in the user
     */
    public function checkPassword($uid, $password) {
        if (strlen($uid) !== 64 || strlen($password) !== 64) {
            return false;
        }

        // Limit access to token and introspection endpoints only
        $requestUri = $this->request->getRequestUri();
        $requestUri = strtok($requestUri,"?");
        $requestUri = rtrim($requestUri, "/");
        if (!str_ends_with($requestUri, 'token') && !str_ends_with($requestUri, 'introspect')) {
            $this->logger->warning('OIDCIdentityProvider BasicAuthBackend: RequestUri was: ' . $requestUri . ' Allowed endpoints are: token, introspect');
            return false;
        }

        try {
            $client = $this->clientMapper->getByIdentifier($uid);
        } catch (ClientNotFoundException $e) {
            $this->logger->notice('OIDCIdentityProvider BasicAuthBackend: Could not find client. Client id was ' . $uid . '.');
            return false;
        }

        if ($client->getType() === 'public') {
            // Only the client id must match for a public client. Else we don't provide an access token!
            if ($client->getClientIdentifier() !== $uid) {
                $this->logger->notice('OIDCIdentityProvider BasicAuthBackend: Client not found. Client id was ' . $uid . '.');
                return false;
            }
        } else {
            // The client id and secret must match. Else we don't provide an access token!
            if ($client->getClientIdentifier() !== $uid || $client->getSecret() !== $password) {
                $this->logger->error('OIDCIdentityProvider BasicAuthBackend: Client authentication failed. Client id was ' . $uid . '.');
                return false;
            }
        }

        return true;
    }

    /**
     * Delete a user. Is not allowed always return false.
     *
     * @param string $uid The username of the user to delete
     *
     * @return bool
     */
    public function deleteUser($uid) {
        return false;
    }

    /**
     * Get display name of the user. Not supported. Always return null.
     *
     * @param string $uid user ID of the user
     *
     * @return string display name
     */
    public function getDisplayName($uid) {
        return null;
    }

    /**
     * Get a list of all display names and user ids. Not supported. Always return an empty array.
     *
     * @return array with all displayNames (value) and the correspondig uids (key)
     */
    public function getDisplayNames($search = '', $limit = null, $offset = null) {
        return array();
    }

    /**
    * Get a list of all users. Not supported. Always an empty array.
    *
    * @return array with all uids
    */
    public function getUsers($search = '', $limit = null, $offset = null) {
        return array();
    }

    /**
    * Get a list of all users, with all data. Not supported. Always an empty array.
    *
    * @return array with all uids and further data
    */
    public function getUsersAllData($search = '', $limit = null, $offset = null) {
        return array();
    }

    /**
    * Lock or unlock a users. Not supported. Always return true.
    *
    * @return current lock status. locked - true else false
    */
    public function toggleLock($uid) {
        return true;
    }

    /**
     * Check if a user exists
     *
     * @param string $uid the username
     *
     * @return boolean
     */
    public function userExists($uid) {
        if (strlen($uid) !== 64) {
            return false;
        }

        try {
            $client = $this->clientMapper->getByIdentifier($uid);
        } catch (ClientNotFoundException $e) {
            $this->logger->notice('OIDCIdentityProvider BasicAuthBackend: Could not find client. Client id was ' . $uid . '.');
            return false;
        }

        if ($client->getClientIdentifier() !== $uid) {
            $this->logger->notice('OIDCIdentityProvider BasicAuthBackend: Client not found. Client id was ' . $uid . '.');
            return false;
        }

        return true;
    }

    /**
     * Determines if the authentication backend can enlist users
     *
     * @return bool
     */
    public function hasUserListings() {
        return false;
    }

    /**
     * Change the display name of a user
     *
     * @param string $uid The username
     * @param string $displayName The new display name
     *
     * @return true/false
     */
    public function setDisplayName($uid, $displayName) {
        return false;
    }

    /**
    * get the user's home directory
    * @param string $uid the username
    * @return boolean/string path
    */
    public function getHome($uid) {
        return false;
    }

    /**
    * set the user's home directory
    * @param string $uid the username
    * @param string $data_path the path
    * @return boolean true on success else false
    */
    public function setHome($uid, $data_path) {
        return false;
    }

    /**
     * Count the users
     * @return int|bool
     */
    public function countUsers() {
        return false;
    }

    /**
     * Backend name to be shown in user management
     * @return string the name of the backend to be shown
     */
    public function getBackendName() {
        return 'OIDCIdentityProvider';
    }
}
