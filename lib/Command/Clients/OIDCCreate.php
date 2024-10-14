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

class OIDCCreate extends Command {
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
        'The signing algorithm to use', 
        'RSA256'
      )
      ->addOption(
        'type', 
        't', 
        InputOption::VALUE_REQUIRED, 
        'The type of the client', 
        'confidential'
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
      // get database connection
      $query = $this->connection->getQueryBuilder();
      // create client
      $res = $this->settingsController->addClient(
        $input->getArgument("name"),
        $input->getArgument("redirect_uris")[0], // @TODO: should accept array
        $input->getOption("algorithm"),
        $input->getOption("type")
      );

      // output created client infos
      $output->writeln(json_encode($res->getData(), JSON_PRETTY_PRINT));
      return 0;
  }

}
