<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Util;

/**
 * Parser for selected application/x-www-form-urlencoded parameters that
 * preserves repeated parameter names.
 *
 * PHP/Nextcloud normally exposes POST form parameters as an associative map,
 * which cannot reliably represent RFC-style repetitions such as
 * "resource=a&resource=b". This helper reads and parses the original form body
 * for the small set of parameters where preserving repetitions is required.
 */
class FormUrlencodedParameterParser {
    /**
     * Read the raw HTTP request body and return all occurrences of the selected
     * parameter names.
     *
     * @param list<string> $parameterNames
     * @return array<string, list<string>>|null Null if the request body could not be read.
     */
    public function readSelectedParameters(array $parameterNames): ?array {
        $body = file_get_contents('php://input');
        if ($body === false) {
            return null;
        }

        return $this->parseSelectedParameters($body, $parameterNames);
    }

    /**
     * Parse selected parameters from an application/x-www-form-urlencoded body
     * without collapsing repeated names.
     *
     * @param string $body
     * @param list<string> $parameterNames
     * @return array<string, list<string>>
     */
    public function parseSelectedParameters(string $body, array $parameterNames): array {
        $result = [];
        foreach ($parameterNames as $parameterName) {
            $result[$parameterName] = [];
        }

        if ($body === '' || $result === []) {
            return $result;
        }

        foreach (explode('&', $body) as $pair) {
            if ($pair === '') {
                continue;
            }

            $separatorPosition = strpos($pair, '=');
            if ($separatorPosition === false) {
                $encodedName = $pair;
                $encodedValue = '';
            } else {
                $encodedName = substr($pair, 0, $separatorPosition);
                $encodedValue = substr($pair, $separatorPosition + 1);
            }

            // application/x-www-form-urlencoded uses percent decoding and maps
            // '+' to a space, which is exactly what urldecode() implements.
            $name = urldecode($encodedName);
            if (!array_key_exists($name, $result)) {
                continue;
            }

            $result[$name][] = urldecode($encodedValue);
        }

        return $result;
    }
}
