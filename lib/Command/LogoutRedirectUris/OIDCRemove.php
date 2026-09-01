<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Command\LogoutRedirectUris;

use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCRemove extends Command {
    public function __construct(private LogoutRedirectUriMapper $mapper, private ClientMapper $clientMapper) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('oidc:remove-logout-redirect-uri')
            ->setDescription('Remove a global or RP-specific accepted OIDC post logout redirect URI')
            ->addArgument('redirect_uri', InputArgument::REQUIRED, 'The logout redirect URI to remove')
            ->addOption('client-id', null, InputOption::VALUE_REQUIRED, 'Public client identifier. Omit for the legacy global allow-list.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $redirectUri = trim((string)$input->getArgument('redirect_uri'));
        try {
            $clientIdentifier = $input->getOption('client-id');
            $client = null;
            if (is_string($clientIdentifier) && trim($clientIdentifier) !== '') {
                $client = $this->clientMapper->getByIdentifier(trim($clientIdentifier));
                if ($client === null) {
                    throw new \RuntimeException("Client '$clientIdentifier' was not found.");
                }
            }
            if ($this->mapper->deleteByRedirectUri($redirectUri, $client?->getId())) {
                $output->writeln("<info>Logout redirect URI `{$redirectUri}` removed.</info>");
            } else {
                $output->writeln("<comment>Logout redirect URI `{$redirectUri}` not found.</comment>");
            }
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}.</error>");
            return Command::FAILURE;
        }
    }
}
