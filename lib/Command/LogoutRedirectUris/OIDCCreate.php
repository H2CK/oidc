<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Command\LogoutRedirectUris;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUri;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCA\OIDCIdentityProvider\Exceptions\CliException;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCP\AppFramework\Services\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCCreate extends Command {
    public function __construct(
        private IAppConfig $appConfig,
        private LogoutRedirectUriMapper $mapper,
        private RedirectUriService $redirectUriService,
        private ClientMapper $clientMapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('oidc:create-logout-redirect-uri')
            ->setDescription('Create a global or RP-specific accepted OIDC post logout redirect URI')
            ->addArgument('redirect_uri', InputArgument::REQUIRED, 'The logout redirect URI to accept')
            ->addOption('client-id', null, InputOption::VALUE_REQUIRED, 'Public client identifier. Omit for the legacy global allow-list.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            $redirectUri = trim((string)$input->getArgument('redirect_uri'));
            $allowSubdomainWildcards = $this->appConfig->getAppValueString(
                Application::APP_CONFIG_ALLOW_SUBDOMAIN_WILDCARDS,
                Application::DEFAULT_ALLOW_SUBDOMAIN_WILDCARDS,
            ) === 'true';
            if (!$this->redirectUriService->isValidRedirectUri($redirectUri, $allowSubdomainWildcards)) {
                throw new CliException("The logout redirect URI '$redirectUri' is not valid according to the configured redirect URI rules.");
            }

            $clientIdentifier = $input->getOption('client-id');
            $client = null;
            if (is_string($clientIdentifier) && trim($clientIdentifier) !== '') {
                $client = $this->clientMapper->getByIdentifier(trim($clientIdentifier));
                if ($client === null) {
                    throw new CliException("Client '$clientIdentifier' was not found.");
                }
            }

            $logoutRedirectUri = new LogoutRedirectUri();
            $logoutRedirectUri->setRedirectUri($redirectUri);
            if ($client !== null) {
                $logoutRedirectUri->setClientId($client->getId());
            }
            $logoutRedirectUri = $this->mapper->insert($logoutRedirectUri);
            $output->writeln(json_encode([
                'id' => $logoutRedirectUri->getId(),
                'client_id' => $client?->getClientIdentifier(),
                'redirect_uri' => $logoutRedirectUri->getRedirectUri(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
