<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Command\Claims;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;
use OCP\AppFramework\Services\IAppConfig;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCList extends Command
{
    /** @var IAppConfig */
    private $appconf;

    /** @var ClientMapper */
    private $clientMapper;
    /** @var CustomClaimMapper */
    private $customClaimMapper;

    public function __construct(
        IAppConfig $appconf,
        ClientMapper $clientMapper,
        CustomClaimMapper $customClaimMapper
    ) {
        parent::__construct();
        $this->appconf = $appconf;
        $this->clientMapper = $clientMapper;
        $this->customClaimMapper = $customClaimMapper;
    }

    protected function configure(): void
    {
        $this
            ->setName('oidc:list-claim')
            ->setDescription('List oidc custom claims');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // get custom claim objects
            $claims = $this->customClaimMapper->findAll();
            foreach ($claims as $claim) {
                $client = $this->clientMapper->getByUid($claim->getClientId());
                $output->writeln("ID: {$claim->getId()}, Client: {$client->getClientIdentifier()}, Name: {$claim->getName()}, Scope: {$claim->getScope()}, Function: {$claim->getFunction()}, Parameter: {$claim->getParameter()}");
            }
            return Command::SUCCESS;
        } catch (\Exception $e) {
            // handle any errors and output a message
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

}
