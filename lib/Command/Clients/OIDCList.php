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

use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCP\AppFramework\Services\IAppConfig;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCList extends Command {
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
      ->setName('oidc:list')
      ->setDescription('List oidc clients');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
      try {
        // get client objects
        $clients = $this->mapper->getClients();
        // output pretty json
        $output->writeln(json_encode($clients, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
      } catch (\Exception $e) {
        // handle any errors and output a message
        $output->writeln("<error>Error: {$e->getMessage()}</error>");
        return Command::FAILURE;
      }
      return 0;
  }

}
