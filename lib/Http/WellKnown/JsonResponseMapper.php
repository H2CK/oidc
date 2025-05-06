<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Http\WellKnown;

use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Http\WellKnown\IResponse;

/**
 * Maps a JSON Response to an IResponse
 */
class JsonResponseMapper implements IResponse {
    /** @var JSONResponse|null */
    private JSONResponse $response;

    /**
     * Creates a JsonResponseMapper
     */
    public function __construct(JSONResponse $response) {
        $this->response = $response;
    }

    /**
     * Converts the JsonResponseMapper back to JSONResponse
     */
    public function toHttpResponse(): Response {
        return $this->response;
    }
}
