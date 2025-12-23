<?php

namespace SmartAuth\Tests\Mocks;

/**
 * Exception thrown by mock json_reply() to capture API responses in tests
 */
class JsonReplyException extends \Exception
{
    private $data;
    private int $httpCode;

    public function __construct($data, int $httpCode)
    {
        $this->data = $data;
        $this->httpCode = $httpCode;
        parent::__construct(is_string($data) ? $data : json_encode($data), $httpCode);
    }

    public function getData()
    {
        return $this->data;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
