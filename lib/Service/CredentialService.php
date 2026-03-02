<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Security\ICredentialsManager;

use Psr\Log\LoggerInterface;

class CredentialService {

    /** @var ICredentialsManager */
    private $credentialsManager;
    /** @var IAppConfig */
    private $appConfig;
    /** @var LoggerInterface */
    private $logger;

    public const PARAM_PRIVATE_KEY = 'private_key';

    /**
     * @param ICredentialsManager $credentialsManager
     * @param IAppConfig $appConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        ICredentialsManager $credentialsManager,
        IAppConfig $appConfig,
        LoggerInterface $logger
    ) {
        $this->credentialsManager = $credentialsManager;
        $this->appConfig = $appConfig;
        $this->logger = $logger;
    }

    /**
     * Returns the private key stored in the credentials manager
     * @return string|null - the private key or null if no private key is stored
     */
    public function getPrivateKey() {
        $this->logger->debug('Retrieving private key for signing tokens');
        $this->migratePrivateKey();
        return $this->credentialsManager->retrieve(
                Application::APP_ID,
                CredentialService::PARAM_PRIVATE_KEY
        );
    }

    /**
     * Stores the private key in the credentials manager
     * @param mixed $privateKeyString
     * @return bool - true if the private key was successfully stored, false otherwise
     */
    public function setPrivateKey($privateKeyString) {
        $this->logger->debug('Setting private key for signing tokens');
        $this->credentialsManager->store(
            Application::APP_ID,
            CredentialService::PARAM_PRIVATE_KEY,
            $privateKeyString
        );
        return true;
    }

    /**
     * Migrate private key from app config to credentials manager
     * @return bool - true if migration was successful or not needed, false if migration failed
     */
    public function migratePrivateKey() {
        $privateKey = $this->appConfig->getAppValueString(CredentialService::PARAM_PRIVATE_KEY);
        if ($privateKey === '') {
            return true;
        }
		$this->logger->debug('Migrating private key for signing tokens');
        $this->appConfig->deleteAppValue(CredentialService::PARAM_PRIVATE_KEY);
        return $this->setPrivateKey($privateKey);
    }

    public function generateKeys(): bool
    {
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

        $this->appConfig->deleteAppValue(CredentialService::PARAM_PRIVATE_KEY);
        $this->setPrivateKey($privateKey);
        $this->appConfig->setAppValueString('public_key', $publicKey);
        $uuid = $this->guidv4();
        $this->appConfig->setAppValueString('kid', $uuid);
        $modulus = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['n']));
        $exponent = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['e']));
        $this->appConfig->setAppValueString('public_key_n', $modulus);
        $this->appConfig->setAppValueString('public_key_e', $exponent);
        return true;
    }

    private function guidv4($data = null)
    {
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
