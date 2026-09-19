<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Command\GroupScopes;

use OCA\OIDCIdentityProvider\Db\GroupScopeMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCRemove extends Command {

    public function __construct(
        private GroupScopeMapper $groupScopeMapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('oidc:group-scopes:delete')
            ->setDescription('Remove the maximum scopes of a group')
            ->addArgument('group_id', InputArgument::REQUIRED, 'The group id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $groupId = (string)$input->getArgument('group_id');
        $this->groupScopeMapper->deleteByGroupId($groupId);
        $output->writeln("<info>Maximum scopes for group `{$groupId}` removed.</info>");
        return Command::SUCCESS;
    }
}
