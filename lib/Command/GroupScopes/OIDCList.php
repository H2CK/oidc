<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Command\GroupScopes;

use OCA\OIDCIdentityProvider\Db\GroupScopeMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCList extends Command {

    public function __construct(
        private GroupScopeMapper $groupScopeMapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('oidc:group-scopes:list')
            ->setDescription('List the maximum scopes configured per group');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        foreach ($this->groupScopeMapper->findAll() as $row) {
            $output->writeln("Group: {$row->getGroupId()}, Scopes: {$row->getScopes()}");
        }
        return Command::SUCCESS;
    }
}
