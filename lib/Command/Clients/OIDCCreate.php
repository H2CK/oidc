<?php

namespace OCA\OIDCIdentityProvider\Command\Clients;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use OCP\AppFramework\Services\IAppConfig;
use OCA\OIDCIdentityProvider\Db\ClientMapper;

class OIDCCreate extends Command {

  /** @var ClientMapper */
  private $clientMapper;
  /** @var IAppConfig */
  private $appconf;

  public function __construct(
    IAppConfig $appconf,
    ClientMapper $clientMapper
  ) {
      parent::__construct();
      $this->appconf = $appconf;
      $this->clientMapper = $clientMapper;
  }

  protected function configure(): void {
    $this
      ->setName('oidc:create')
      ->setDescription('Create oidc clients');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    // get database connection
    $db = \OC::$server->getDatabaseConnection();
    $clients = $this->clientMapper->getClients();
    $output->writeln('<info>Clients:</info> ' . json_encode($clients, JSON_PRETTY_PRINT));
    $output->writeln('<comment>No clients found.</comment>');
    return 0;
  }
}
