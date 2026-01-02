<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Command\Claims;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use OCP\AppFramework\Services\IAppConfig;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\CustomClaim;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;

class OIDCRemove extends Command
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
        CustomClaimMapper $customClaimMapper) {
        parent::__construct();
        $this->appconf = $appconf;
        $this->clientMapper = $clientMapper;
        $this->customClaimMapper = $customClaimMapper;
    }

    protected function configure(): void
    {
        $this
            ->setName('oidc:remove-claim')
            ->setDescription('Remove an oidc claim')
            ->addArgument(
                'client_id',
                InputArgument::REQUIRED,
                'The identifier of the client'
            )
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the claim'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $clientId = $input->getArgument('client_id');
            $client = $this->clientMapper->getByIdentifier($clientId);

            $this->customClaimMapper->deleteByClientAndName(
                $client->getId(),
                $input->getArgument('name')
            );
            $output->writeln("<info>Claim `{$input->getArgument('name')}` for client `{$clientId}` removed.</info>");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}.</error>");
            return Command::FAILURE;
        }
    }
}
