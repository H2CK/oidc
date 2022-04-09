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

use OC\Authentication\Exceptions\ExpiredTokenException;
use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Token\IProvider as TokenProvider;
use OC\Security\Bruteforce\Throttler;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountProperty;
use OCP\Accounts\IAccountManager;
use OCP\IURLGenerator;
use OCP\AppFramework\Services\IAppConfig;

class OIDCApiController extends ApiController {
	/** @var AccessTokenMapper */
	private $accessTokenMapper;
	/** @var ClientMapper */
	private $clientMapper;
	/** @var ICrypto */
	private $crypto;
	/** @var TokenProvider */
	private $tokenProvider;
	/** @var ISecureRandom */
	private $secureRandom;
	/** @var ITimeFactory */
	private $time;
	/** @var Throttler */
	private $throttler;
	/** @var IUserManager */
	private $userManager;
	/** @var IGroupManager */
	private $groupManager;
	/** @var IAccountManager */
	private $accountManager;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var IAppConfig */
	private $appConfig;

	public function __construct(string $appName,
								IRequest $request,
								ICrypto $crypto,
								AccessTokenMapper $accessTokenMapper,
								ClientMapper $clientMapper,
								TokenProvider $tokenProvider,
								ISecureRandom $secureRandom,
								ITimeFactory $time,
								Throttler $throttler,
								IUserManager $userManager,
								IGroupManager $groupManager,
								IAccountManager $accountManager,
								IURLGenerator $urlGenerator,
								IAppConfig $appConfig) {
		parent::__construct($appName, $request);
		$this->crypto = $crypto;
		$this->accessTokenMapper = $accessTokenMapper;
		$this->clientMapper = $clientMapper;
		$this->tokenProvider = $tokenProvider;
		$this->secureRandom = $secureRandom;
		$this->time = $time;
		$this->throttler = $throttler;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->accountManager = $accountManager;
		$this->urlGenerator = $urlGenerator;
		$this->appConfig = $appConfig;
	}

	/**
	 * @CORS
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @param string $grant_type
	 * @param string $code
	 * @param string $refresh_token
	 * @param string $client_id
	 * @param string $client_secret
	 * @return JSONResponse
	 */
	public function getToken($grant_type, $code, $refresh_token, $client_id, $client_secret): JSONResponse {
		$expireTime = $this->appConfig->getAppValue('expire_time');
		// We only handle two types
		if ($grant_type !== 'authorization_code' && $grant_type !== 'refresh_token') {
			return new JSONResponse([
				'error' => 'invalid_grant',
				'error_description' => 'Invalid grant_type provided. Must be authorization_code or refresh_token.',
			], Http::STATUS_BAD_REQUEST);
		}

		// We handle the initial and refresh tokens the same way
		if ($grant_type === 'refresh_token') {
			$code = $refresh_token;
		}

		try {
			$accessToken = $this->accessTokenMapper->getByCode($code);
		} catch (AccessTokenNotFoundException $e) {
			return new JSONResponse([
				'error' => 'invalid_request',
				'error_description' => 'Could not find access token for code or refresh_token.',
			], Http::STATUS_BAD_REQUEST);
		}

		try {
			$client = $this->clientMapper->getByUid($accessToken->getClientId());
		} catch (ClientNotFoundException $e) {
			return new JSONResponse([
				'error' => 'invalid_request',
				'error_description' => 'Could not find client for access token.',
			], Http::STATUS_BAD_REQUEST);
		}

		if (isset($this->request->server['PHP_AUTH_USER'])) {
			$client_id = $this->request->server['PHP_AUTH_USER'];
			$client_secret = $this->request->server['PHP_AUTH_PW'];
		}

		if ($client->getType() === 'public') {
			// Only the client id must match for a public client. Else we don't provide an access token!
			if ($client->getClientIdentifier() !== $client_id) {
				return new JSONResponse([
					'error' => 'invalid_client',
					'error_description' => 'Client not found.',
				], Http::STATUS_BAD_REQUEST);
			}
		} else {
			// The client id and secret must match. Else we don't provide an access token!
			if ($client->getClientIdentifier() !== $client_id || $client->getSecret() !== $client_secret) {
				return new JSONResponse([
					'error' => 'invalid_client',
					'error_description' => 'Client authentication failed.',
				], Http::STATUS_BAD_REQUEST);
			}
		}

		// The accessToken must not be expired
		if ($this->time->getTime() > $accessToken->getRefreshed() + $expireTime ) {
			$this->accessTokenMapper->delete($accessToken);
			return new JSONResponse([
				'error' => 'invalid_grant',
				'error_description' => 'Access token already expired.',
			], Http::STATUS_BAD_REQUEST);
		}

		$newAccessToken = $this->secureRandom->generate(72, ISecureRandom::CHAR_ALPHANUMERIC);
		$newCode = $this->secureRandom->generate(128, ISecureRandom::CHAR_ALPHANUMERIC);
		$accessToken->setHashedCode(hash('sha512', $newCode));
		$accessToken->setAccessToken($newAccessToken);
		$accessToken->setRefreshed($this->time->getTime() + $expireTime);
		$this->accessTokenMapper->update($accessToken);

		$uid = $accessToken->getUserId();
		$user = $this->userManager->get($uid);
		$groups = $this->groupManager->getUserGroups($user);
		$account = $this->accountManager->getAccount($user);

		$issuer = $this->request->getServerProtocol() . '://' . $this->request->getServerHost() . $this->urlGenerator->getWebroot();
		$nonce = $accessToken->getNonce();

		$jwt_payload = [
			'iss' => $issuer,
			'sub' => $uid,
			'aud' => $client->getClientIdentifier(),
			'exp' => $this->time->getTime() + $expireTime,
			'auth_time' => $accessToken->getCreated(),
			'iat' => $this->time->getTime(),
			'acr' => '0',
			'azp' => $client->getClientIdentifier(),
			'preferred_username' => $uid,
			'scope' => $accessToken->getScope(),
			'nbf' => $this->time->getTime(),
			'jti' => $accessToken->getId(),
		];

		if (!empty($nonce)) {
			$nonce_payload = [
				'nonce' => $nonce
			];
			$jwt_payload = array_merge($jwt_payload, $nonce_payload);
		}

		$roles = [];
		// Add roles
		foreach ($groups as $group) {
			array_push($roles, $group->getGID());
		}

		// Check for scopes
		// OpenID Connect requests MUST contain the openid scope value. - This implementation does not enforce that openid is specified.
		// OPTIONAL scope values of profile, email, address, phone, and offline_access are also defined. See Section 2.4 for more about the scope values defined by this document.
		$scopeArray = preg_split('/ +/', $accessToken->getScope());
		if (in_array("roles", $scopeArray)) {
			$roles_payload = [
				'roles' => $roles
			];
			$jwt_payload = array_merge($jwt_payload, $roles_payload);
		}
		if (in_array("groups", $scopeArray)) {
			$roles_payload = [
				'groups' => $roles
			];
			$jwt_payload = array_merge($jwt_payload, $roles_payload);
		}
		if (in_array("profile", $scopeArray)) {
			$profile = [
				'name' => $user->getDisplayName(),
				'updated_at' => $user->getLastLogin(),
			];
			if ($account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_WEBSITE)->getValue() != '') {
				$profile = array_merge($profile, ['website' => $account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_WEBSITE)->getValue()]);
			}
			// Possible further values
			// 'family_name' => ,
			// 'given_name' => ,
			// 'middle_name' => ,
			// 'nickname' => ,
			// 'profile' => ,
			// 'picture' => ,
			// 'gender' => ,
			// 'birthdate' => ,
			// 'zoneinfo' => ,
			// 'locale' => ,
			$jwt_payload = array_merge($jwt_payload, $profile);
		}
		if (in_array("email", $scopeArray) && $user->getEMailAddress() !== null) {
			$email = [
				'email' => $user->getEMailAddress(),
			];
            if ($account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_EMAIL)->getVerified()) {
				$email = array_merge($email, ['email_verified' => true]);
			} else {
                $email = array_merge($email, ['email_verified' => false]);
            }
			$jwt_payload = array_merge($jwt_payload, $email);
		}

		$payload = json_encode($jwt_payload);
		$base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

		$base64UrlHeader = '';
		$base64UrlSignature = '';

		$signing_alg = $client->getSigningAlg(); // HS256 or RS256
		if ($signing_alg === 'HS256') {
			$header = json_encode(['typ' => 'JWT', 'alg' => $signing_alg]);
			$base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
			$signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $client->getSecret(), true);
			$base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
		} else {
			$kid = $this->appConfig->getAppValue('kid');
			$header = json_encode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $kid]);
			$base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
			openssl_sign("$base64UrlHeader.$base64UrlPayload", $signature, $this->appConfig->getAppValue('private_key'), 'sha256WithRSAEncryption');
			$base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
		}

		$jwt = "$base64UrlHeader.$base64UrlPayload.$base64UrlSignature";

		return new JSONResponse(
			[
				'access_token' => $newAccessToken,
				'token_type' => 'Bearer',
				'expires_in' => $expireTime,
				'refresh_token' => $newCode,
				'id_token' => $jwt,
			]
		);
	}
}
