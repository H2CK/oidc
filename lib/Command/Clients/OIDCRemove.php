<?php

namespace OCA\OIDCIdentityProvider\Command\Clients;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use OCP\AppFramework\Services\IAppConfig;
use OCP\IDBConnection;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Controller\SettingsController;

class OIDCRemove extends Command {

  /** @var IAppConfig */
  private $appconf;
  /** @var IDBConnection */
  private $connection;

  public function __construct(
    IAppConfig $appconf,
    IDBConnection $connection,
    SettingsController $settingsController
  ) {
      parent::__construct();
      $this->appconf = $appconf;
      $this->connection = $connection;
      $this->settingsController = $settingsController;
  }

  protected function configure(): void {
    $this
      ->setName('oidc:remove')
      ->setDescription('Remove an oidc client')
      ->addArgument(
        'name',
        InputArgument::REQUIRED, 
        'The name of the client to remove'
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
      $name = $input->getArgument("name");
      // get database connection
      $query = $this->connection->getQueryBuilder();
      // get object id of client
      $query->select('*')
        ->from('oidc_clients')
        ->where($query->expr()->eq('name', $query->createNamedParameter($name)));

      $res = $query->executeQuery();

      if($client = $res->fetchOne()) {
        $res = $this->settingsController->deleteClient($client);
        // output created client infos
        $output->writeln('<info>Client `' . $name . '` removed.</info>');
      } else {
        // output created client infos
        $output->writeln('<comment>Client `' . $name . '` not found.</comment>');
      }

      return 0;
  }

}
