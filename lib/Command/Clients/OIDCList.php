<?php

namespace OCA\OIDCIdentityProvider\Command\Clients;

use OCP\AppFramework\Services\IAppConfig;
use OCP\IDBConnection;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCList extends Command {
  /** @var IAppConfig */
  private $appconf;
  /** @var IDBConnection */
  private $connection;

  public function __construct(
    IAppConfig $appconf,
    IDBConnection $connection
  ) {
      parent::__construct();
      $this->appconf = $appconf;
      $this->connection = $connection;
  }

  protected function configure(): void {
    $this
      ->setName('oidc:list')
      ->setDescription('List oidc clients');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
      // get database connection
      $query = $this->connection->getQueryBuilder();
      $query->select('*')->from('oidc_clients')->executeQuery();
      $result = $query->executeQuery();
      // fetch all clients
      $clients = $result->fetchAll();
      // output pretty json
      $output->writeln(json_encode($clients, JSON_PRETTY_PRINT));
      return 0;
  }

}
