<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Command\LogoutRedirectUris;

use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCRemove extends Command {
    public function __construct(private LogoutRedirectUriMapper $mapper) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('oidc:remove-logout-redirect-uri')
            ->setDescription('Remove an accepted OIDC logout redirect URI')
            ->addArgument('redirect_uri', InputArgument::REQUIRED, 'The logout redirect URI to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $redirectUri = $input->getArgument('redirect_uri');
        try {
            if ($this->mapper->deleteByRedirectUri($redirectUri)) {
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
