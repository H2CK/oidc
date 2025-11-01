<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use Psr\Log\LoggerInterface;

class AddOfflineAccessToClients implements IRepairStep {
    /** @var ClientMapper */
    private $clientMapper;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ClientMapper $clientMapper,
        LoggerInterface $logger
    ) {
        $this->clientMapper = $clientMapper;
        $this->logger = $logger;
    }

    public function getName(): string {
        return 'Add offline_access scope to existing OIDC clients';
    }

    public function run(IOutput $output) {
        $output->info("Adding offline_access scope to all existing OIDC clients");

        try {
            $clients = $this->clientMapper->findAll();
            $updatedCount = 0;

            foreach ($clients as $client) {
                $allowedScopes = $client->getAllowedScopes();

                // Check if offline_access is already present
                if (!empty($allowedScopes) && strpos($allowedScopes, 'offline_access') !== false) {
                    $this->logger->debug('Client ' . $client->getClientIdentifier() . ' already has offline_access scope');
                    continue;
                }

                // Add offline_access to the allowed scopes
                if (empty($allowedScopes) || trim($allowedScopes) === '') {
                    $newScopes = 'offline_access';
                } else {
                    $newScopes = trim($allowedScopes) . ' offline_access';
                }

                $client->setAllowedScopes($newScopes);
                $this->clientMapper->update($client);
                $updatedCount++;

                $this->logger->info('Added offline_access to client: ' . $client->getClientIdentifier() . ' (new scopes: ' . $newScopes . ')');
                $output->info('  Updated client: ' . $client->getName() . ' (' . $client->getClientIdentifier() . ')');
            }

            if ($updatedCount > 0) {
                $output->info("Successfully added offline_access scope to {$updatedCount} client(s)");
                $this->logger->info("Repair step completed: Added offline_access to {$updatedCount} clients");
            } else {
                $output->info("No clients needed updating");
            }
        } catch (\Exception $e) {
            $this->logger->error('Error adding offline_access to clients: ' . $e->getMessage());
            $output->warning('Error occurred: ' . $e->getMessage());
        }
    }
}
