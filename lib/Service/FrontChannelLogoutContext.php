<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCP\IRequest;

/**
 * Request-local hand-off between the public Nextcloud logout event and the
 * global response middleware.
 *
 * Global middleware can be resolved through the container of the currently
 * dispatched app while the event listener is resolved through the OIDC app
 * container. Therefore the hand-off must not depend on both components sharing
 * the same service instance. All app containers resolve the same public
 * IRequest server service for a request, so a WeakMap keyed by that request
 * object provides a container-independent request scope without retaining data
 * after the request object becomes unreachable.
 */
class FrontChannelLogoutContext {
    /** @var \WeakMap<IRequest,array{logoutTriggered:bool,frontChannelUris:list<string>}>|null */
    private static ?\WeakMap $states = null;

    public function __construct(
        private IRequest $request,
    ) {
    }

    /** @param list<string> $frontChannelUris */
    public function recordLogout(array $frontChannelUris): void {
        $state = $this->getState();
        $state['logoutTriggered'] = true;
        $state['frontChannelUris'] = array_values(array_unique(array_merge(
            $state['frontChannelUris'],
            array_values(array_filter(
                $frontChannelUris,
                static fn ($uri): bool => is_string($uri) && $uri !== ''
            ))
        )));
        $this->states()[$this->request] = $state;
    }

    /**
     * Consume the current request's logout marker.
     *
     * null means no real logout occurred. An empty array means a real logout
     * occurred but no RP requires Front-Channel Logout.
     *
     * @return list<string>|null
     */
    public function consumeLogoutUris(): ?array {
        $state = $this->getState();
        if (!$state['logoutTriggered']) {
            return null;
        }

        $uris = $state['frontChannelUris'];
        unset($this->states()[$this->request]);
        return $uris;
    }

    /** @return array{logoutTriggered:bool,frontChannelUris:list<string>} */
    private function getState(): array {
        return $this->states()[$this->request] ?? [
            'logoutTriggered' => false,
            'frontChannelUris' => [],
        ];
    }

    /** @return \WeakMap<IRequest,array{logoutTriggered:bool,frontChannelUris:list<string>}> */
    private function states(): \WeakMap {
        return self::$states ??= new \WeakMap();
    }
}
