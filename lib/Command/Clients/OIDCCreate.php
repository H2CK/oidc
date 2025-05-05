<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2025 Thorsten Jagel <dev@jagel.net>
 *
 * @author Thorsten Jagel <dev@jagel.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\OIDCIdentityProvider\Command\Clients;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;

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

  public function __construct(
    IAppConfig $appconf,
    ClientMapper $mapper
  ) {
      parent::__construct();
      $this->appconf = $appconf;
      $this->mapper = $mapper;
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
        'opaque'
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
      try {
          // create new client
          $client = new Client(
            $input->getArgument('name'),
            $input->getArgument('redirect_uris'),
            $input->getOption('algorithm'),
            $input->getOption('type'),
            $input->getOption('flow'),
            $input->getOption('token_type')
          );
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
