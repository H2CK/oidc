<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Command\GroupScopes;

use OCA\OIDCIdentityProvider\Db\GroupScopeMapper;
use OCP\IGroupManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCSet extends Command {

    public function __construct(
        private GroupScopeMapper $groupScopeMapper,
        private IGroupManager $groupManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('oidc:group-scopes:set')
            ->setDescription('Set the maximum scopes that members of a group may be issued')
            ->addArgument('group_id', InputArgument::REQUIRED, 'The group id')
            ->addArgument('scopes', InputArgument::REQUIRED, 'Space-separated maximum scopes (openid profile email roles are always allowed)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $groupId = (string)$input->getArgument('group_id');
        if (!$this->groupManager->groupExists($groupId)) {
            $output->writeln("<error>Group `{$groupId}` does not exist.</error>");
            return Command::FAILURE;
        }
        $scopes = implode(' ', preg_split('/\s+/', trim((string)$input->getArgument('scopes')), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if (strlen($scopes) > 512) {
            $output->writeln('<error>Scopes must not exceed 512 characters.</error>');
            return Command::FAILURE;
        }
        $this->groupScopeMapper->upsert($groupId, $scopes);
        $output->writeln("<info>Maximum scopes for group `{$groupId}` set to `{$scopes}`.</info>");
        return Command::SUCCESS;
    }
}
