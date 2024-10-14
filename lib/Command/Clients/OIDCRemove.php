<?php

namespace OCA\OIDCIdentityProvider\Command\Clients;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OIDCRemove extends Command {

  protected function configure(): void {
    $this
      ->setName('oidc:remove')
      ->setDescription('Remove an oidc client');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $output->writeln('<info>Command executed.</info>');
    return 0;
  }
}

