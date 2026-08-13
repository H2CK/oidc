<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Command\LogoutRedirectUris;

use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCList extends Command {
    public function __construct(private LogoutRedirectUriMapper $mapper) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('oidc:list-logout-redirect-uri')
            ->setDescription('List accepted OIDC logout redirect URIs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            $logoutRedirectUris = array_map(static fn ($logoutRedirectUri) => [
                'id' => $logoutRedirectUri->getId(),
                'redirect_uri' => $logoutRedirectUri->getRedirectUri(),
            ], $this->mapper->getAll());
            $output->writeln(json_encode($logoutRedirectUris, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
