<?php

declare(strict_types=1);

namespace WF\API\Automation\Traits;

use Log;

trait LoggingTrait
{
    protected string $logContext = 'Unknown';

    /**
     * Set the logging context (usually the class name)
     */
    protected function setLogContext(string $context): void
    {
        $this->logContext = $context;
    }

    /**
     * Log the start of an operation with context
     */
    protected function logOperationStart(string $operation, array $context = []): string
    {
        $operationId = uniqid('op_');
        $startTime = microtime(true);

        $logData = array_merge([
          'operation_id' => $operationId,
          'timestamp' => date('c'),
          'start_time' => $startTime
        ], $this->sanitizeForLog($context));

        Log::info("[$this->logContext] START $operation: " . json_encode($logData));

        return $operationId;
    }

    /**
     * Log the end of an operation with duration
     */
    protected function logOperationEnd(string $operation, string $operationId, bool $success = true, array $context = []): void
    {
        $duration = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        $logData = array_merge([
          'operation_id' => $operationId,
          'duration_ms' => round($duration * 1000, 2),
          'success' => $success,
          'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ], $this->sanitizeForLog($context));

        $level = $success ? 'info' : 'error';
        Log::$level("[$this->logContext] END $operation: " . json_encode($logData));
    }

    /**
     * Log API request details
     */
    protected function logApiRequest(string $service, string $endpoint, array $payload = []): string
    {
        $requestId = uniqid('req_');

        $logData = [
          'request_id' => $requestId,
          'service' => $service,
          'endpoint' => $endpoint,
          'payload_size' => strlen(json_encode($payload)),
          'payload_preview' => $this->getPayloadPreview($payload)
        ];

        Log::info("[$this->logContext] API Request to $service: " . json_encode($logData));

        return $requestId;
    }

    /**
     * Log API response details
     */
    protected function logApiResponse(string $service, string $requestId, $response, float $duration, bool $success = true): void
    {
        $logData = [
          'request_id' => $requestId,
          'service' => $service,
          'duration_ms' => round($duration * 1000, 2),
          'success' => $success,
          'response_size' => is_string($response) ? strlen($response) : strlen(json_encode($response))
        ];

        if (!$success && $response) {
            $logData['error_preview'] = $this->getErrorPreview($response);
        }

        $level = $success ? 'info' : 'error';
        Log::$level("[$this->logContext] API Response from $service: " . json_encode($logData));
    }

    /**
     * Log decision points in business logic
     */
    protected function logDecision(string $decision, $value, string $reason, array $context = []): void
    {
        $logData = array_merge([
          'decision' => $decision,
          'value' => $value,
          'reason' => $reason,
          'timestamp' => date('c')
        ], $this->sanitizeForLog($context));

        Log::info("[$this->logContext] Decision: $decision - " . json_encode($logData));
    }

    /**
     * Log performance metrics
     */
    protected function logPerformanceMetric(string $metric, float $value, string $unit = 'ms', array $tags = []): void
    {
        $logData = [
          'metric' => $metric,
          'value' => $value,
          'unit' => $unit,
          'tags' => $tags,
          'context' => $this->logContext
        ];

        Log::info("METRIC:$metric - " . json_encode($logData));
    }

    /**
     * Remove sensitive data from arrays before logging
     */
    protected function sanitizeForLog(array $data): array
    {
        $sensitiveKeys = [
          'ssn', 'password', 'token', 'secret', 'api_key', 'client_secret',
          'certificate_password', 'authorization', 'pwd', 'pass'
        ];

        $sanitized = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);

            // Check if key contains sensitive data
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains($lowerKey, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeForLog($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Get a preview of payload for logging (first 200 chars)
     */
    private function getPayloadPreview($payload): string
    {
        $json = json_encode($this->sanitizeForLog(is_array($payload) ? $payload : ['data' => $payload]));
        return strlen($json) > 200 ? substr($json, 0, 200) . '...' : $json;
    }

    /**
     * Get error preview for logging
     */
    private function getErrorPreview($response): string
    {
        if (is_string($response)) {
            return substr($response, 0, 500);
        }

        if (is_array($response)) {
            return json_encode(array_slice($response, 0, 5));
        }

        return 'Unknown error format';
    }

    /**
     * Create a structured log entry for audit trail
     */
    protected function auditLog(string $action, string $entityType, $entityId, array $changes = []): void
    {
        $logData = [
          'action' => $action,
          'entity_type' => $entityType,
          'entity_id' => $entityId,
          'changes' => $this->sanitizeForLog($changes),
          'user' => $_SERVER['HTTP_X_GRAVITY_USER'] ?? 'system',
          'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
          'timestamp' => date('c')
        ];

        Log::info("AUDIT:$action - " . json_encode($logData));
    }

    /**
     * Log a simple message with context
     */
    protected function logInfo(string $message, array $context = []): void
    {
        if (empty($context)) {
            Log::info("[$this->logContext] $message");
        } else {
            Log::info("[$this->logContext] $message: " . json_encode($this->sanitizeForLog($context)));
        }
    }

    /**
     * Log an error with context
     */
    protected function logError(string $message, array $context = []): void
    {
        if (empty($context)) {
            Log::error("[$this->logContext] $message");
        } else {
            Log::error("[$this->logContext] $message: " . json_encode($this->sanitizeForLog($context)));
        }
    }

    /**
     * Log a warning with context
     */
    protected function logWarning(string $message, array $context = []): void
    {
        if (empty($context)) {
            Log::warn("[$this->logContext] $message");
        } else {
            Log::warn("[$this->logContext] $message: " . json_encode($this->sanitizeForLog($context)));
        }
    }

    /**
     * Log debug information
     */
    protected function logDebug(string $message, array $context = []): void
    {
        if (empty($context)) {
            Log::debug("[$this->logContext] $message");
        } else {
            Log::debug("[$this->logContext] $message: " . json_encode($this->sanitizeForLog($context)));
        }
    }
}
