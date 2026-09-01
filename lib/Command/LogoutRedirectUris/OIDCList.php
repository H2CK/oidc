<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Command\LogoutRedirectUris;

use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCList extends Command {
    public function __construct(private LogoutRedirectUriMapper $mapper, private ClientMapper $clientMapper) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('oidc:list-logout-redirect-uri')
            ->setDescription('List global or RP-specific accepted OIDC post logout redirect URIs')
            ->addOption('client-id', null, InputOption::VALUE_REQUIRED, 'Public client identifier. Omit to list the legacy global allow-list.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            $clientIdentifier = $input->getOption('client-id');
            $client = null;
            if (is_string($clientIdentifier) && trim($clientIdentifier) !== '') {
                $client = $this->clientMapper->getByIdentifier(trim($clientIdentifier));
                if ($client === null) {
                    throw new \RuntimeException("Client '$clientIdentifier' was not found.");
                }
            }
            $entries = $client === null ? $this->mapper->getGlobal() : $this->mapper->getByClientId($client->getId());
            $result = array_map(static fn ($entry) => [
                'id' => $entry->getId(),
                'client_id' => $client?->getClientIdentifier(),
                'redirect_uri' => $entry->getRedirectUri(),
            ], $entries);
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
