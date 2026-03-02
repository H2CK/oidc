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
use OCA\OIDCIdentityProvider\Service\CredentialService;

class CreateKeys implements IRepairStep {
    /** @var IAppConfig */
    private $appConfig;
    /** @var CredentialService */
    private $credentialService;

    public function __construct(IAppConfig $appConfig, CredentialService $credentialService) {
        $this->appConfig = $appConfig;
        $this->credentialService = $credentialService;
    }

    public function getName(): string {
        return 'Create key material for OpenID Connect';
    }

    public function run(IOutput $output) {
        $output->info("Check for OIDC key material.");
        if ($this->appConfig->getAppValueString('kid') === '' ) {
            $output->info("Creating OIDC key material.");
            $this->credentialService->generateKeys();
        }
        if ($this->appConfig->getAppValueString('expire_time') === '' ) {
            $this->appConfig->setAppValueString('expire_time', '900');
        }
    }
}
