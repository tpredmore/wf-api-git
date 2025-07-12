<?php

declare(strict_types=1);

namespace WF\API\Automation\Config;

class LoggingConfig
{
    /**
     * Log levels for different components
     */
    public const LOG_LEVELS = [
      'default' => 'info',
      'PreQualEngine' => 'debug',
      'BureauClient' => 'info',
      'RiskScorer' => 'debug',
      'ValuationProvider' => 'info',
      'Cache' => 'warning',
      'Controller' => 'info'
    ];

    /**
     * Components that should log performance metrics
     */
    public const PERFORMANCE_TRACKING = [
      'PreQualEngine',
      'BureauClient',
      'ValuationProvider',
      'RiskScorer'
    ];

    /**
     * Slow operation thresholds (in seconds)
     */
    public const SLOW_THRESHOLDS = [
      'bureau_pull' => 3.0,
      'valuation' => 2.0,
      'risk_scoring' => 0.5,
      'cache_operation' => 0.1,
      'total_request' => 5.0
    ];

    /**
     * Fields to always redact from logs
     */
    public const REDACTED_FIELDS = [
      'ssn',
      'password',
      'token',
      'secret',
      'api_key',
      'client_secret',
      'certificate_password',
      'authorization',
      'pwd',
      'pass',
      'dob',
      'date_of_birth',
      'account_number',
      'routing_number'
    ];

    /**
     * Metrics to send to DataDog
     */
    public const DATADOG_METRICS = [
      'bureau_pull_duration' => ['unit' => 'milliseconds', 'tags' => ['bureau', 'success']],
      'valuation_duration' => ['unit' => 'milliseconds', 'tags' => ['provider', 'success']],
      'risk_score_calculation' => ['unit' => 'milliseconds', 'tags' => ['tier']],
      'cache_hit_rate' => ['unit' => 'percentage', 'tags' => ['bureau']],
      'approval_rate' => ['unit' => 'percentage', 'tags' => ['risk_tier', 'bureau']],
      'request_duration' => ['unit' => 'milliseconds', 'tags' => ['endpoint', 'success']]
    ];

    /**
     * Get log level for a component
     */
    public static function getLogLevel(string $component): string
    {
        return self::LOG_LEVELS[$component] ?? self::LOG_LEVELS['default'];
    }

    /**
     * Check if component should track performance
     */
    public static function shouldTrackPerformance(string $component): bool
    {
        return in_array($component, self::PERFORMANCE_TRACKING);
    }

    /**
     * Get slow threshold for an operation
     */
    public static function getSlowThreshold(string $operation): float
    {
        return self::SLOW_THRESHOLDS[$operation] ?? self::SLOW_THRESHOLDS['total_request'];
    }

    /**
     * Check if a field should be redacted
     */
    public static function shouldRedact(string $fieldName): bool
    {
        $lowerField = strtolower($fieldName);
        foreach (self::REDACTED_FIELDS as $redacted) {
            if (str_contains($lowerField, $redacted)) {
                return true;
            }
        }
        return false;
    }
}
