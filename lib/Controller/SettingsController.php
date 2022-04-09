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

use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\ISecureRandom;
use OCP\AppFramework\Services\IAppConfig;

class SettingsController extends Controller {
	/** @var ClientMapper */
	private $clientMapper;
	/** @var ISecureRandom */
	private $secureRandom;
	/** @var AccessTokenMapper  */
	private $accessTokenMapper;
	/** @var IL10N */
	private $l;
	/** @var IAppConfig */
	private $appConfig;

	public const validChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

	public function __construct(string $appName,
								IRequest $request,
								ClientMapper $clientMapper,
								ISecureRandom $secureRandom,
								AccessTokenMapper $accessTokenMapper,
								IL10N $l,
								IAppConfig $appConfig
	) {
		parent::__construct($appName, $request);
		$this->secureRandom = $secureRandom;
		$this->clientMapper = $clientMapper;
		$this->accessTokenMapper = $accessTokenMapper;
		$this->l = $l;
		$this->appConfig = $appConfig;
	}

	public function addClient(string $name,
							  string $redirectUri,
							  string $signingAlg,
							  string $type): JSONResponse {
		if (filter_var($redirectUri, FILTER_VALIDATE_URL) === false) {
			return new JSONResponse(['message' => $this->l->t('Your redirect URL needs to be a full URL for example: https://yourdomain.com/path')], Http::STATUS_BAD_REQUEST);
		}

		$client = new Client();
		$client->setName($name);
		$client->setRedirectUri($redirectUri);
		$client->setSecret($this->secureRandom->generate(64, self::validChars));
		$client->setClientIdentifier($this->secureRandom->generate(64, self::validChars));
		if ($signingAlg === 'HS256') {
			$client->setSigningAlg('HS256');
		} else {
			$client->setSigningAlg('RS256');
		}
		if ($type === 'public') {
			$client->setType($type);
		} else {
			$client->setType('confidential');
		}
		$client = $this->clientMapper->insert($client);

		$result = [
			'id' => $client->getId(),
			'name' => $client->getName(),
			'redirectUri' => $client->getRedirectUri(),
			'clientId' => $client->getClientIdentifier(),
			'clientSecret' => $client->getSecret(),
			'signingAlg' => $client->getSigningAlg(),
			'type' => $client->getType(),
		];

		return new JSONResponse($result);
	}

	public function deleteClient(int $id): JSONResponse {
		$client = $this->clientMapper->getByUid($id);
		$this->accessTokenMapper->deleteByClientId($id);
		$this->clientMapper->delete($client);
		return new JSONResponse([]);
	}

	public function setTokenExpireTime(string $expireTime): JSONResponse {
		$options = array(
			'options' => array(
				'default' => 900,
				'min_range' => 60,
				'max_range' => 3600,
			),
			'flags' => FILTER_FLAG_ALLOW_OCTAL,
		);
		$finalExpireTime = filter_var($expireTime, FILTER_VALIDATE_INT, $options);
		$finalExpireTime = strval($finalExpireTime);
		$this->appConfig->setAppValue('expire_time', $finalExpireTime);
		$result = [
			'expire_time' => $expireTime,
		];
		return new JSONResponse($result);
	}

	public function regenerateKeys(): JSONResponse {
		$config = array(
			"digest_alg" => 'sha512',
			"private_key_bits" => 4096,
			"private_key_type" => OPENSSL_KEYTYPE_RSA
		);
		$keyPair = openssl_pkey_new($config);
		$privateKey = NULL;
		openssl_pkey_export($keyPair, $privateKey);
		$keyDetails = openssl_pkey_get_details($keyPair);
		$publicKey = $keyDetails['key'];

		$this->appConfig->setAppValue('private_key', $privateKey);
		$this->appConfig->setAppValue('public_key', $publicKey);
		$uuid = $this->guidv4();
		$this->appConfig->setAppValue('kid', $uuid);
		$modulus = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['n']));
		$exponent = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['e']));
		$this->appConfig->setAppValue('public_key_n', $modulus);
		$this->appConfig->setAppValue('public_key_e', $exponent);
		$result = [
			'public_key' => $publicKey,
		];
		return new JSONResponse($result);
	}

	function guidv4($data = null) {
		// Generate 16 bytes (128 bits) of random data or use the data passed into the function.
		$data = $data ?? random_bytes(16);
		assert(strlen($data) == 16);

		// Set version to 0100
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		// Set bits 6-7 to 10
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);

		// Output the 36 character UUID.
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
