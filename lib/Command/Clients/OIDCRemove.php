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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use OCP\AppFramework\Services\IAppConfig;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;

class OIDCRemove extends Command {

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
      ->setName('oidc:remove')
      ->setDescription('Remove an oidc client')
      ->addArgument(
        'client_id',
        InputArgument::REQUIRED,
        'The identifier of the client to remove'
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    // retrieve client identifier
    $client_id = $input->getArgument("client_id");
    // remove client
    try {
      if ($this->mapper->deleteByIdentifier($client_id)) {
        $output->writeln("<info>Client `{$client_id}` removed.</info>");
      } else {
        $output->writeln("<comment>Client `{$client_id}` not found.</comment>");
      }
      return Command::SUCCESS;
    } catch (\Exception $e) {
      $output->writeln("<error>Error: {$e->getMessage()}.</error>");
      return Command::FAILURE;
    }
  }
}
