<?php

/**
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SmartAuth\DolibarrMapping;

/**
 * Thrown by dmTrait::importMappedData() when the input payload contains
 * fields that are not declared as writable on the target mapper, or when
 * an unsupported feature (e.g. 'lines' on a header-only import) is
 * requested.
 *
 * Controllers catch this exception and return HTTP 400 with the per-field
 * error map. The exception MUST NOT be confused with json_reply()'s
 * JsonReplyEmittedError -- it carries structured field-level errors and
 * is meant to be caught and translated at the HTTP boundary.
 *
 * Usage from a controller:
 *   try {
 *       $sanitized = $mapper->importMappedData($payload);
 *   } catch (\SmartAuth\DolibarrMapping\MapperValidationException $e) {
 *       json_reply(['errors' => $e->getErrors()], 400);
 *   }
 */
class MapperValidationException extends \Exception
{
    /**
     * Map of fieldName => human-readable error message.
     *
     * @var array<string,string>
     */
    private $errors;

    /**
     * @param array<string,string> $errors  field => message
     */
    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct(sprintf(
            'Mapper input validation failed: %d field(s) rejected (%s)',
            count($errors),
            implode(', ', array_keys($errors))
        ));
    }

    /**
     * @return array<string,string>
     */
    public function getErrors()
    {
        return $this->errors;
    }
}
