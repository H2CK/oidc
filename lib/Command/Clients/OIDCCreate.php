<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Command\Clients;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCA\OIDCIdentityProvider\Exceptions\CliException;
use OCA\OIDCIdentityProvider\AppInfo\Application;

use OCP\AppFramework\Services\IAppConfig;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCCreate extends Command {
  /** @var IAppConfig */
  private $appconf;

  /** @var ClientMapper */
  private $mapper;
  /** @var RedirectUriSevice */
  private $redirectUriService;

  public function __construct(
    IAppConfig $appconf,
    ClientMapper $mapper,
    RedirectUriService $redirectUriService,
  ) {
      parent::__construct();
      $this->appconf = $appconf;
      $this->mapper = $mapper;
      $this->redirectUriService = $redirectUriService;
  }

  protected function configure(): void {
    $this
      ->setName('oidc:create')
      ->setDescription('Create oidc client')
      ->addArgument(
        'name',
        InputArgument::REQUIRED,
        'The name of the client'
      )
      ->addArgument(
        'redirect_uris',
        InputArgument::REQUIRED | InputArgument::IS_ARRAY,
        'An array of redirect uris'
      )
      ->addOption(
        'algorithm',
        'a',
        InputOption::VALUE_REQUIRED,
        'The signing algorithm to use. Can be ´RS256´ or ´HS256´.',
        'RS256'
      )
      ->addOption(
        'flow',
        'f',
        InputOption::VALUE_REQUIRED,
        'The flow type to use for authentication. Can be ´code´ or ´code id_token´.',
        'code'
      )
      ->addOption(
        'type',
        't',
        InputOption::VALUE_REQUIRED,
        'The type of the client. Can be ´public´ or ´confidential´.',
        'confidential'
      )
      ->addOption(
        'token_type',
        null,
        InputOption::VALUE_OPTIONAL,
        'The type of the access token created for the client. If set to ´jwt´ a RFC9068 conforming access token is generated.',
        null
      )
      ->addOption(
        'allowed_scopes',
        null,
        InputOption::VALUE_OPTIONAL,
        'The allowed scopes for the client. E.g. ´openid profile roles´. If not defined any scope is accepted.',
        ''
      )
      ->addOption(
        'email_regex',
        null,
        InputOption::VALUE_OPTIONAL,
        'The regular expression to select the used email from all email addresses of a user (primary and secondary). If not set always the primary email address will be used.',
        ''
      )
      ->addOption(
        'client_id',
        null,
        InputOption::VALUE_OPTIONAL,
        'The client id to be used. If not provided the client id will be generated internally. Requirements: chars A-Za-z0-9 & min length 32 & max length 64',
        ''
      )
      ->addOption(
        'client_secret',
        null,
        InputOption::VALUE_OPTIONAL,
        'The client secret to be used. If not provided the client secret will be generated internally. Requirements: chars A-Za-z0-9 & min length 32 & max length 64',
        ''
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
      try {
          // Get token type from option or use configured default
          $tokenType = $input->getOption('token_type');
          if (empty($tokenType)) {
              $tokenType = $this->appconf->getAppValueString(
                  Application::APP_CONFIG_DEFAULT_TOKEN_TYPE,
                  Application::DEFAULT_TOKEN_TYPE
              );
          }

          $redirect_uris = $input->getArgument('redirect_uris');
          foreach ($redirect_uris as $uri) {
              if (!$this->redirectUriService->isValidRedirectUri($uri, $this->appconf->getAppValueString(Application::APP_CONFIG_ALLOW_SUBDOMAIN_WILDCARDS, Application::DEFAULT_ALLOW_SUBDOMAIN_WILDCARDS) === 'true')) {
                  throw new CliException("The redirect uri '$uri' is not valid according to the configured redirect uri rules.");
              }
          }

          // create new client
          $client = new Client(
            $input->getArgument('name'),
            $redirect_uris,
            $input->getOption('algorithm'),
            $input->getOption('type'),
            $input->getOption('flow'),
            $tokenType,
            $input->getOption('allowed_scopes'),
            $input->getOption('email_regex')
          );
          $clientId = $input->getOption('client_id');
          $clientSecret = $input->getOption('client_secret');
          if (isset($clientId) && trim($clientId) !== '') {
            if (filter_var($clientId, FILTER_VALIDATE_REGEXP, array("options" => array("regexp" => "/^[A-Za-z0-9]{32,64}$/"))) === false) {
                throw new CliException ("Your clientId must comply with the following rules: chars A-Za-z0-9 & min length 32 & max length 64");
            }
            $client->setClientIdentifier($clientId);
          }

          if (isset($clientSecret) && trim($clientSecret) !== '') {
            if (filter_var($clientSecret, FILTER_VALIDATE_REGEXP, array("options" => array("regexp" => "/^[A-Za-z0-9]{32,64}$/"))) === false) {
                throw new CliException ("Your clientSecret must comply with the following rules: chars A-Za-z0-9 & min length 32 & max length 64");
            }
            $client->setSecret($clientSecret);
          }
          // insert new client into database
          $client = $this->mapper->insert($client);
          // print client as pretty json
          $output->writeln(json_encode($client, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
          return Command::SUCCESS;
      } catch (\Exception $e) {
          // handle any errors and output a message
          $output->writeln("<error>Error: {$e->getMessage()}</error>");
          return Command::FAILURE;
      }
  }

}
