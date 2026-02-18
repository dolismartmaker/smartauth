<?php

/**
 * ResponseException.php
 *
 * Exception thrown by OAuth2 controllers in test mode instead of calling exit().
 * This allows unit tests to verify response content without terminating the process.
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace SmartAuth\Api\OAuth2;

class ResponseException extends \Exception
{
    /**
     * HTTP status code
     * @var int
     */
    private $statusCode;

    /**
     * Response body (decoded from JSON)
     * @var array
     */
    private $responseBody;

    /**
     * Response headers
     * @var array
     */
    private $headers;

    /**
     * Constructor
     *
     * @param array $body Response body
     * @param int $statusCode HTTP status code
     * @param array $headers Response headers
     */
    public function __construct(array $body, int $statusCode = 200, array $headers = [])
    {
        $this->responseBody = $body;
        $this->statusCode = $statusCode;
        $this->headers = $headers;

        parent::__construct(json_encode($body), $statusCode);
    }

    /**
     * Get HTTP status code
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get response body as array
     *
     * @return array
     */
    public function getResponseBody(): array
    {
        return $this->responseBody;
    }

    /**
     * Get response headers
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Check if response is an error
     *
     * @return bool
     */
    public function isError(): bool
    {
        return isset($this->responseBody['error']);
    }

    /**
     * Get error code if present
     *
     * @return string|null
     */
    public function getErrorCode(): ?string
    {
        return $this->responseBody['error'] ?? null;
    }

    /**
     * Get error description if present
     *
     * @return string|null
     */
    public function getErrorDescription(): ?string
    {
        return $this->responseBody['error_description'] ?? null;
    }
}
