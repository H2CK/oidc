<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Command\Claims;

use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\CustomClaim;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;

use OCP\AppFramework\Services\IAppConfig;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCCreate extends Command
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
        CustomClaimMapper $customClaimMapper,
    ) {
        parent::__construct();
        $this->appconf = $appconf;
        $this->clientMapper = $clientMapper;
        $this->customClaimMapper = $customClaimMapper;
    }

    protected function configure(): void
    {
        $this
            ->setName('oidc:create-claim')
            ->setDescription('Create custom claim for oidc client')
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the custom claim'
            )
            ->addArgument(
                'scope',
                InputArgument::REQUIRED,
                'The scope for which the claim is applied'
            )
            ->addArgument(
                'client_id',
                InputArgument::REQUIRED,
                'The client (client id) for which the claim is applied'
            )
            ->addArgument(
                'function',
                InputArgument::REQUIRED,
                'The function that is used to set the value for the claim.'
            )
            ->addOption(
                'parameter',
                'p',
                InputOption::VALUE_OPTIONAL,
                'The parameter(s) for the function (comma separated if multiple)',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Validate client existence
            $clientId = $input->getArgument('client_id');
            $client = $this->clientMapper->getByIdentifier($clientId);

            $claim = new CustomClaim();
            $claim->setClientId($client->getId());
            $claim->setName($input->getArgument('name'));
            $claim->setScope($input->getArgument('scope'));
            $claim->setFunction($input->getArgument('function'));
            $claim->setParameter($input->getOption('parameter'));

            $customClaim = $this->customClaimMapper->createOrUpdate($claim);
            // print custom as pretty json
            $output->writeln(json_encode($customClaim->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            // handle any errors and output a message
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

}
