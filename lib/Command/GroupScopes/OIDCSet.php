<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Command\GroupScopes;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
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
        private ClientMapper $clientMapper,
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
        $this->warnAboutUnknownScopes($output, $scopes);
        return Command::SUCCESS;
    }

    /**
     * A limit only ever subtracts, so a scope no client asks for is inert --
     * which is exactly what a typo looks like. Warn rather than reject: a
     * dynamically registered client may request a scope no configured client
     * lists.
     */
    private function warnAboutUnknownScopes(OutputInterface $output, string $scopes): void {
        $known = preg_split('/\s+/', strtolower(Application::DEFAULT_SCOPE), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $known[] = 'offline_access';
        foreach ($this->clientMapper->getClients() as $client) {
            $known = array_merge($known, preg_split('/\s+/', strtolower((string)$client->getAllowedScopes()), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }

        $unknown = array_diff(
            preg_split('/\s+/', strtolower($scopes), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            $known,
        );
        if ($unknown !== []) {
            $output->writeln('<comment>Note: no configured client allows ' . implode(', ', $unknown)
                . '. That is fine for a scope only dynamically registered clients request, but check for typos.</comment>');
        }
    }
}
