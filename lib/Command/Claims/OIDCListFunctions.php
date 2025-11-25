<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Command\Claims;

use OCA\OIDCIdentityProvider\Service\CustomClaimService;
use OCP\AppFramework\Services\IAppConfig;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCListFunctions extends Command
{
    /** @var IAppConfig */
    private $appconf;

    /** @var CustomClaimService */
    private $customClaimService;

    public function __construct(
        IAppConfig $appconf,
        CustomClaimService $customClaimService
    ) {
        parent::__construct();
        $this->appconf = $appconf;
        $this->customClaimService = $customClaimService;
    }

    protected function configure(): void
    {
        $this
            ->setName('oidc:list-claim-functions')
            ->setDescription('List functions to be used for oidc custom claims');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            foreach (CustomClaimService::FUNCTIONS as $func) {
                $output->writeln("{$func['name']}");
            }
            return Command::SUCCESS;
        } catch (\Exception $e) {
            // handle any errors and output a message
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

}
