<?php

declare(strict_types=1);

namespace WF\API\Automation\Services;

use Log;
use WF\API\Automation\Config\LoggingConfig;

class RequestLogger
{
    private static ?string $requestId = null;
    private static ?float $startTime = null;
    private static array $breadcrumbs = [];
    private static array $metrics = [];
    private static array $context = [];

    /**
     * Initialize request logging
     */
    public static function startRequest(string $endpoint, string $method, array $headers = [], ?array $requestData = null): string
    {
        self::$requestId = uniqid('req_');
        self::$startTime = microtime(true);
        self::$breadcrumbs = [];
        self::$metrics = [];
        self::$context = [];

        $logData = [
          'request_id' => self::$requestId,
          'endpoint' => $endpoint,
          'method' => $method,
          'timestamp' => date('c'),
          'user' => $headers['X-Gravity-User'] ?? 'unknown',
          'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
          'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];

        // Log request headers (sanitized)
        $safeHeaders = self::sanitizeHeaders($headers);
        if (!empty($safeHeaders)) {
            $logData['headers'] = $safeHeaders;
        }

        // Add request data preview if provided
        if ($requestData !== null) {
            $logData['request_preview'] = self::getDataPreview($requestData);
        }

        Log::info("REQUEST_START: " . json_encode($logData));

        // Send metric to DataDog
        self::sendMetric('request.started', 1, ['endpoint' => $endpoint, 'method' => $method]);

        return self::$requestId;
    }

    /**
     * Add a breadcrumb to the request trail
     */
    public static function addBreadcrumb(string $message, array $context = [], ?string $component = null): void
    {
        if (self::$requestId === null) {
            return;
        }

        $breadcrumb = [
          'time' => microtime(true) - self::$startTime,
          'message' => $message,
          'context' => self::sanitizeContext($context),
          'component' => $component ?? 'unknown'
        ];

        self::$breadcrumbs[] = $breadcrumb;

        // Check if this is a slow operation
        if ($breadcrumb['time'] > 1.0) { // Over 1 second
            self::logSlowOperation($message, $breadcrumb['time'], $context);
        }

        // Also log immediately for real-time debugging if in DEBUG mode
        if (defined('DEBUG') && DEBUG) {
            $logData = [
              'request_id' => self::$requestId,
              'elapsed_ms' => round($breadcrumb['time'] * 1000, 2),
              'component' => $component,
              'message' => $message
            ];

            if (!empty($context)) {
                $logData['context'] = self::sanitizeContext($context);
            }

            Log::debug("BREADCRUMB: " . json_encode($logData));
        }
    }

    /**
     * Add a performance metric
     */
    public static function addMetric(string $name, float $value, array $tags = []): void
    {
        if (self::$requestId === null) {
            return;
        }

        $metric = [
          'name' => $name,
          'value' => $value,
          'tags' => $tags,
          'timestamp' => microtime(true) - self::$startTime
        ];

        self::$metrics[] = $metric;

        // Send to DataDog if configured
        if (isset(LoggingConfig::DATADOG_METRICS[$name])) {
            self::sendMetric($name, $value, $tags);
        }
    }

    /**
     * Set context data that will be included in all logs
     */
    public static function setContext(string $key, $value): void
    {
        self::$context[$key] = $value;
    }

    /**
     * Log the end of request processing
     */
    public static function endRequest(bool $success = true, $response = null, ?string $error = null): void
    {
        if (self::$requestId === null) {
            return;
        }

        $duration = microtime(true) - self::$startTime;

        $logData = [
          'request_id' => self::$requestId,
          'duration_ms' => round($duration * 1000, 2),
          'success' => $success,
          'breadcrumb_count' => count(self::$breadcrumbs),
          'metric_count' => count(self::$metrics),
          'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
          'context' => self::$context
        ];

        if (!$success && $error !== null) {
            $logData['error'] = $error;
        }

        if ($response !== null) {
            $logData['response_size'] = is_string($response) ? strlen($response) : strlen(json_encode($response));
            $logData['response_preview'] = self::getDataPreview($response);
        }

        // Log performance breakdown if we have breadcrumbs
        if (!empty(self::$breadcrumbs)) {
            $logData['performance_breakdown'] = self::getPerformanceBreakdown();
        }

        // Log collected metrics
        if (!empty(self::$metrics)) {
            $logData['metrics'] = self::$metrics;
        }

        $level = $success ? 'info' : 'error';
        Log::$level("REQUEST_END: " . json_encode($logData));

        // Send final metrics
        self::sendMetric('request.duration', $duration * 1000, [
          'endpoint' => self::$context['endpoint'] ?? 'unknown',
          'success' => $success ? 'true' : 'false'
        ]);

        // Log slow requests as warnings
        $slowThreshold = LoggingConfig::getSlowThreshold('total_request');
        if ($duration > $slowThreshold) {
            self::logSlowRequest($duration, $slowThreshold);
        }

        // Reset for next request
        self::reset();
    }

    /**
     * Handle exceptions with full context
     */
    public static function logException(\Throwable $e, array $context = []): void
    {
        if (self::$requestId === null) {
            // No active request, just log the exception
            Log::error("EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return;
        }

        $logData = [
          'request_id' => self::$requestId,
          'exception_class' => get_class($e),
          'message' => $e->getMessage(),
          'file' => $e->getFile(),
          'line' => $e->getLine(),
          'context' => array_merge(self::$context, self::sanitizeContext($context)),
          'breadcrumbs' => self::$breadcrumbs
        ];

        Log::error("REQUEST_EXCEPTION: " . json_encode($logData));

        // Also log the stack trace separately for better readability
        Log::error("STACK_TRACE for " . self::$requestId . ":\n" . $e->getTraceAsString());
    }

    /**
     * Get the current request ID
     */
    public static function getRequestId(): ?string
    {
        return self::$requestId;
    }

    /**
     * Check if request logging is active
     */
    public static function isActive(): bool
    {
        return self::$requestId !== null;
    }

    /**
     * Get performance breakdown from breadcrumbs
     */
    private static function getPerformanceBreakdown(): array
    {
        $breakdown = [];
        $lastTime = 0;

        foreach (self::$breadcrumbs as $crumb) {
            $duration = ($crumb['time'] - $lastTime) * 1000; // Convert to ms
            $breakdown[] = [
              'step' => $crumb['message'],
              'component' => $crumb['component'] ?? 'unknown',
              'duration_ms' => round($duration, 2),
              'cumulative_ms' => round($crumb['time'] * 1000, 2)
            ];
            $lastTime = $crumb['time'];
        }

        // Sort by duration to find slowest operations
        usort($breakdown, fn($a, $b) => $b['duration_ms'] <=> $a['duration_ms']);

        return $breakdown;
    }

    /**
     * Log slow operation warning
     */
    private static function logSlowOperation(string $operation, float $duration, array $context): void
    {
        $logData = [
          'request_id' => self::$requestId,
          'operation' => $operation,
          'duration_ms' => round($duration * 1000, 2),
          'context' => self::sanitizeContext($context)
        ];

        Log::warn("SLOW_OPERATION: " . json_encode($logData));
    }

    /**
     * Log slow request warning with details
     */
    private static function logSlowRequest(float $duration, float $threshold): void
    {
        $slowestOps = array_slice(self::getPerformanceBreakdown(), 0, 5); // Top 5 slowest

        $logData = [
          'request_id' => self::$requestId,
          'duration_ms' => round($duration * 1000, 2),
          'threshold_ms' => round($threshold * 1000, 2),
          'slowest_operations' => $slowestOps,
          'total_breadcrumbs' => count(self::$breadcrumbs)
        ];

        Log::warn("SLOW_REQUEST: " . json_encode($logData));
    }

    /**
     * Send metric to DataDog
     */
    private static function sendMetric(string $metric, float $value, array $tags = []): void
    {
        // This would integrate with your DataDog setup
        $logData = [
          'metric' => 'wildfire.api.' . $metric,
          'value' => $value,
          'tags' => array_merge([
            'environment' => ENVIRONMENT ?? 'unknown',
            'request_id' => self::$requestId
          ], $tags),
          'timestamp' => time()
        ];

        // Log to the DataDog file that will be picked up by the agent
        Log::info("DATADOG_METRIC: " . json_encode($logData));
    }

    /**
     * Get data preview for logging
     */
    private static function getDataPreview($data, int $maxLength = 500): string
    {
        if (is_string($data)) {
            return strlen($data) > $maxLength ? substr($data, 0, $maxLength) . '...' : $data;
        }

        if (is_array($data) || is_object($data)) {
            $json = json_encode(self::sanitizeContext((array)$data));
            return strlen($json) > $maxLength ? substr($json, 0, $maxLength) . '...' : $json;
        }

        return (string)$data;
    }

    /**
     * Sanitize context data for logging
     */
    private static function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (LoggingConfig::shouldRedact($key)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeContext($value);
            } elseif (is_object($value)) {
                $sanitized[$key] = get_class($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize headers for logging
     */
    private static function sanitizeHeaders(array $headers): array
    {
        $safe = [];

        foreach ($headers as $key => $value) {
            if (LoggingConfig::shouldRedact($key)) {
                $safe[$key] = '[REDACTED]';
            } else {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /**
     * Reset the logger state
     */
    private static function reset(): void
    {
        self::$requestId = null;
        self::$startTime = null;
        self::$breadcrumbs = [];
        self::$metrics = [];
        self::$context = [];
    }

    /**
     * Log a simple info message associated with current request
     */
    public static function info(string $message, array $context = []): void
    {
        if (self::$requestId === null) {
            // No active request, just log normally
            if (empty($context)) {
                Log::info($message);
            } else {
                Log::info($message . ": " . json_encode($context));
            }
            return;
        }

        $logData = [
          'request_id' => self::$requestId,
          'message' => $message
        ];

        if (!empty($context)) {
            $logData = array_merge($logData, self::sanitizeContext($context));
        }

        Log::info("REQUEST_INFO: " . json_encode($logData));
    }

    /**
     * Log an error associated with current request
     */
    public static function error(string $message, array $context = []): void
    {
        if (self::$requestId === null) {
            // No active request, just log normally
            if (empty($context)) {
                Log::error($message);
            } else {
                Log::error($message . ": " . json_encode($context));
            }
            return;
        }

        $logData = [
          'request_id' => self::$requestId,
          'message' => $message,
          'elapsed_ms' => round((microtime(true) - self::$startTime) * 1000, 2)
        ];

        if (!empty($context)) {
            $logData = array_merge($logData, self::sanitizeContext($context));
        }

        Log::error("REQUEST_ERROR: " . json_encode($logData));
    }
}