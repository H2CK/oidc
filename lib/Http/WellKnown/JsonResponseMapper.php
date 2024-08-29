<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2024 Thorsten Jagel <dev@jagel.net>
 *
 * @author Thorsten Jagel <dev@jagel.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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
