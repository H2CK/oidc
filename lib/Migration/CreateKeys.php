<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
        if ($this->appConfig->getAppValueString('kid') === '' ) {
            $output->info("Creating OIDC key material.");
            $config = array(
                "digest_alg" => 'sha512',
                "private_key_bits" => 4096,
                "private_key_type" => OPENSSL_KEYTYPE_RSA
            );
            $keyPair = openssl_pkey_new($config);
            $privateKey = null;
            openssl_pkey_export($keyPair, $privateKey);
            $keyDetails = openssl_pkey_get_details($keyPair);
            $publicKey = $keyDetails['key'];

            $this->appConfig->setAppValueString('private_key', $privateKey);
            $this->appConfig->setAppValueString('public_key', $publicKey);
            $uuid = $this->guidv4();
            $this->appConfig->setAppValueString('kid', $uuid);
            $modulus = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['n']));
            $exponent = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['e']));
            $this->appConfig->setAppValueString('public_key_n', $modulus);
            $this->appConfig->setAppValueString('public_key_e', $exponent);
        }
        if ($this->appConfig->getAppValueString('expire_time') === '' ) {
            $this->appConfig->setAppValueString('expire_time', '900');
        }
    }

    public function guidv4($data = null) {
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
