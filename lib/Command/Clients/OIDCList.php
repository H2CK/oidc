<?php

namespace OCA\OIDCIdentityProvider\Command\Clients;

use OCA\OIDCIdentityProvider\Db\ClientMapper;

use OCP\AppFramework\Services\IAppConfig;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCList extends Command {
  /** @var IAppConfig */
  private $appconf;

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
      } catch (Exception $e) {
        // handle any errors and output a message
        $output->writeln("<error>Error: {$e->getMessage()}</error>");
        return Command::FAILURE;
      }
      return 0;
  }

}
