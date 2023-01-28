<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2023 Thorsten Jagel <dev@jagel.net>
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
namespace OCA\OIDCIdentityProvider\Migration;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\AppFramework\Services\IAppConfig;

class CreateKeys implements IRepairStep {
	/** @var IAppConfig */
	private $appConfig;

	public function __construct(IAppConfig $appConfig) {
		$this->appConfig = $appConfig;
	}

	public function getName(): string {
		return 'Create key material for OpenID Connect';
	}

	public function run(IOutput $output) {
		$output->info("Check for OIDC key material.");
		if ($this->appConfig->getAppValue('kid') === '' ) {
			$output->info("Creating OIDC key material.");
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
		}
		if ($this->appConfig->getAppValue('expire_time') === '' ) {
			$this->appConfig->setAppValue('expire_time', '900');
		}
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
