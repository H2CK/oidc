<?php

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
      if ($this->mapper->deleteByIdentifier($client_id))
        $output->writeln("<info>Client `{$client_id}` removed.</info>");
      else
        $output->writeln("<comment>Client `{$client_id}` not found.</comment>");
      return Command::SUCCESS;
    } catch (\Exception $e) {
      $output->writeln("<error>Error: {$e->getMessage()}.</error>");      
      return Command::FAILURE;
    }
  }
}
