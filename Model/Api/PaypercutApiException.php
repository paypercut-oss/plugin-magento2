<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Api;

/**
 * A structured failure returned by the Paypercut API.
 *
 * Extends \Exception so existing catch blocks keep working. The message still
 * carries the platform's prose for admin comments and the log; telemetry drops
 * it and reports the code, param and trace id instead, because the platform
 * quotes submitted input back inside that prose.
 */
class PaypercutApiException extends \Exception
{
    /**
     * @var int
     */
    private $statusCode;

    /**
     * @var string
     */
    private $errorCode;

    /**
     * @var string
     */
    private $errorType;

    /**
     * @var string
     */
    private $param;

    /**
     * @var string
     */
    private $traceId;

    /**
     * @param string $message
     * @param int $statusCode
     * @param string $errorCode
     * @param string $errorType
     * @param string $param
     * @param string $traceId
     */
    public function __construct(
        string $message,
        int $statusCode,
        string $errorCode = '',
        string $errorType = '',
        string $param = '',
        string $traceId = ''
    ) {
        parent::__construct($message, $statusCode);

        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->errorType = $errorType;
        $this->param = $param;
        $this->traceId = $traceId;
    }

    /**
     * Build one from a decoded Paypercut error body.
     *
     * @param string $message
     * @param int $statusCode
     * @param array $body
     * @return self
     */
    public static function fromBody(string $message, int $statusCode, array $body): self
    {
        $error = isset($body['error']) && is_array($body['error']) ? $body['error'] : [];

        return new self(
            $message,
            $statusCode,
            (string) ($body['code'] ?? $body['error_code'] ?? $error['code'] ?? ''),
            (string) ($body['type'] ?? $error['type'] ?? ''),
            (string) ($body['param'] ?? $error['param'] ?? ''),
            (string) ($body['trace_id'] ?? $error['trace_id'] ?? '')
        );
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return string
     */
    public function getErrorType(): string
    {
        return $this->errorType;
    }

    /**
     * @return string
     */
    public function getParam(): string
    {
        return $this->param;
    }

    /**
     * @return string
     */
    public function getTraceId(): string
    {
        return $this->traceId;
    }
}
