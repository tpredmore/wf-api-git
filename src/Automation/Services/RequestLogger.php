<?php

declare(strict_types=1);

namespace WF\API\Automation\Services;

use Log;

class RequestLogger
{
    private static ?string $requestId = null;
    private static ?float $startTime = null;
    private static array $breadcrumbs = [];

    /**
     * Initialize request logging
     */
    public static function startRequest(string $endpoint, string $method, array $headers = []): string
    {
        self::$requestId = uniqid('req_');
        self::$startTime = microtime(true);
        self::$breadcrumbs = [];

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

        Log::info("REQUEST_START: " . json_encode($logData));

        return self::$requestId;
    }

    /**
     * Add a breadcrumb to the request trail
     */
    public static function addBreadcrumb(string $message, array $context = []): void
    {
        if (self::$requestId === null) {
            return;
        }

        $breadcrumb = [
          'time' => microtime(true) - self::$startTime,
          'message' => $message,
          'context' => $context
        ];

        self::$breadcrumbs[] = $breadcrumb;

        // Also log immediately for real-time debugging
        $logData = [
          'request_id' => self::$requestId,
          'elapsed_ms' => round($breadcrumb['time'] * 1000, 2),
          'message' => $message
        ];

        if (!empty($context)) {
            $logData['context'] = $context;
        }

        Log::debug("BREADCRUMB: " . json_encode($logData));
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
          'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ];

        if (!$success && $error !== null) {
            $logData['error'] = $error;
        }

        if ($response !== null) {
            $logData['response_size'] = is_string($response) ? strlen($response) : strlen(json_encode($response));
        }

        // Log performance breakdown if we have breadcrumbs
        if (!empty(self::$breadcrumbs)) {
            $logData['performance_breakdown'] = self::getPerformanceBreakdown();
        }

        $level = $success ? 'info' : 'error';
        Log::$level("REQUEST_END: " . json_encode($logData));

        // Log slow requests as warnings
        if ($duration > 3.0) { // 3 seconds
            $slowLogData = [
              'request_id' => self::$requestId,
              'duration_ms' => round($duration * 1000, 2),
              'breadcrumbs' => self::$breadcrumbs
            ];
            Log::warn("SLOW_REQUEST: " . json_encode($slowLogData));
        }

        // Reset for next request
        self::$requestId = null;
        self::$startTime = null;
        self::$breadcrumbs = [];
    }

    /**
     * Get the current request ID
     */
    public static function getRequestId(): ?string
    {
        return self::$requestId;
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
              'duration_ms' => round($duration, 2),
              'cumulative_ms' => round($crumb['time'] * 1000, 2)
            ];
            $lastTime = $crumb['time'];
        }

        return $breakdown;
    }

    /**
     * Sanitize headers for logging
     */
    private static function sanitizeHeaders(array $headers): array
    {
        $safe = [];
        $sensitiveHeaders = ['authorization', 'x-gravity-auth-token', 'cookie'];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveHeaders)) {
                $safe[$key] = '[REDACTED]';
            } else {
                $safe[$key] = $value;
            }
        }

        return $safe;
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
            $logData = array_merge($logData, $context);
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
          'message' => $message
        ];

        if (!empty($context)) {
            $logData = array_merge($logData, $context);
        }

        Log::error("REQUEST_ERROR: " . json_encode($logData));
    }
}
