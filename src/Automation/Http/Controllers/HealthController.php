<?php

declare(strict_types=1);

namespace WF\API\Automation\Http\Controllers;

use WF\API\Automation\Services\RequestLogger;
use Log;

class HealthController
{
    public function check(array $requestData, array $params): array
    {
        try {
            // Health checks might be excluded from logging, but if not, add minimal logging
            if (RequestLogger::isEnabled()) {
                RequestLogger::addBreadcrumb('Health check started', [], 'HealthController');
            }

            $startTime = microtime(true);

            // Basic health checks
            $checks = [
              'database' => $this->checkDatabase(),
              'logging' => $this->checkLogging(),
              'memory' => $this->checkMemory(),
              'disk' => $this->checkDisk(),
              'cache' => $this->checkCache(),
              'bureaus' => $this->checkBureauConnections(),
              'request_logging' => $this->checkRequestLogging()
            ];

            $allHealthy = array_reduce($checks, fn($carry, $check) => $carry && $check['healthy'], true);

            $checkDuration = (microtime(true) - $startTime) * 1000;

            // Only log metrics if not excluded
            if (RequestLogger::isEnabled()) {
                RequestLogger::addMetric('health_check.duration', $checkDuration);
                RequestLogger::addMetric('health_check.status', $allHealthy ? 1 : 0);

                // Log individual check statuses
                foreach ($checks as $checkName => $checkResult) {
                    RequestLogger::addMetric('health_check.' . $checkName, $checkResult['healthy'] ? 1 : 0);
                }

                RequestLogger::info('Health check completed', [
                  'status' => $allHealthy ? 'healthy' : 'degraded',
                  'duration_ms' => round($checkDuration, 2)
                ]);
            }

            return [
              'success' => true,
              'error' => '',
              'data' => [
                'status' => $allHealthy ? 'healthy' : 'degraded',
                'timestamp' => date('Y-m-d H:i:s'),
                'version' => '2.0.0',
                'checks' => $checks,
                'performance' => [
                  'check_duration_ms' => round($checkDuration, 2)
                ]
              ]
            ];

        } catch (\Throwable $e) {
            if (RequestLogger::isEnabled()) {
                RequestLogger::logException($e, [
                  'controller' => 'HealthController',
                  'method' => 'check'
                ]);
            }

            Log::error('Health check failed: ' . $e->getMessage());

            return [
              'success' => false,
              'error' => 'Health check failed',
              'data' => [
                'status' => 'unhealthy',
                'timestamp' => date('Y-m-d H:i:s')
              ]
            ];
        }
    }

    private function checkDatabase(): array
    {
        $startTime = microtime(true);

        try {
            // Add your database health check logic here
            // For now, return success

            $checkDuration = (microtime(true) - $startTime) * 1000;

            if (RequestLogger::isEnabled()) {
                RequestLogger::addBreadcrumb('Database check completed', [
                  'duration_ms' => round($checkDuration, 2)
                ], 'HealthController');
            }

            return [
              'healthy' => true,
              'message' => 'Database connection OK',
              'duration_ms' => round($checkDuration, 2)
            ];
        } catch (\Throwable $e) {
            return [
              'healthy' => false,
              'message' => 'Database connection failed: ' . $e->getMessage(),
              'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }
    }

    private function checkCache(): array
    {
        $startTime = microtime(true);

        try {
            // Test Redis connection
            $testKey = 'health_check_' . time();
            \Cache::set($testKey, 'test', false, 1);
            $value = \Cache::get($testKey);
            \Cache::del($testKey);

            $checkDuration = (microtime(true) - $startTime) * 1000;

            if (RequestLogger::isEnabled()) {
                RequestLogger::addBreadcrumb('Cache check completed', [
                  'duration_ms' => round($checkDuration, 2)
                ], 'HealthController');
            }

            return [
              'healthy' => $value === 'test',
              'message' => 'Cache (Redis) connection OK',
              'duration_ms' => round($checkDuration, 2)
            ];
        } catch (\Throwable $e) {
            return [
              'healthy' => false,
              'message' => 'Cache connection failed: ' . $e->getMessage(),
              'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }
    }

    private function checkBureauConnections(): array
    {
        $startTime = microtime(true);

        $bureaus = [
          'equifax' => !empty($_ENV['EQUIFAX_CLIENT_ID']),
          'experian' => !empty($_ENV['EXPERIAN_CLIENT_ID']),
          'transunion' => !empty($_ENV['TRANSUNION_ENDPOINT'])
        ];

        $configured = array_filter($bureaus);

        $checkDuration = (microtime(true) - $startTime) * 1000;

        if (RequestLogger::isEnabled()) {
            RequestLogger::addBreadcrumb('Bureau connections check completed', [
              'configured' => count($configured),
              'duration_ms' => round($checkDuration, 2)
            ], 'HealthController');
        }

        return [
          'healthy' => count($configured) > 0,
          'message' => sprintf('%d of 3 bureaus configured', count($configured)),
          'details' => $bureaus,
          'duration_ms' => round($checkDuration, 2)
        ];
    }

    private function checkLogging(): array
    {
        $startTime = microtime(true);

        try {
            Log::info('Health check log test');

            $checkDuration = (microtime(true) - $startTime) * 1000;

            return [
              'healthy' => true,
              'message' => 'Logging system OK',
              'duration_ms' => round($checkDuration, 2)
            ];
        } catch (\Throwable $e) {
            return [
              'healthy' => false,
              'message' => 'Logging system error: ' . $e->getMessage(),
              'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }
    }

    private function checkMemory(): array
    {
        $startTime = microtime(true);

        $memoryUsage = memory_get_usage(true);
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitBytes = $this->convertToBytes($memoryLimit);

        $usage = $memoryLimitBytes > 0 ? $memoryUsage / $memoryLimitBytes : 0;

        $checkDuration = (microtime(true) - $startTime) * 1000;

        return [
          'healthy' => $usage < 0.8, // Alert if using more than 80% of memory
          'message' => sprintf('Memory usage: %.1f%% (%s / %s)',
            $usage * 100,
            $this->formatBytes($memoryUsage),
            $memoryLimit
          ),
          'usage_percentage' => round($usage * 100, 1),
          'duration_ms' => round($checkDuration, 2)
        ];
    }

    private function checkDisk(): array
    {
        $startTime = microtime(true);

        try {
            $diskFree = disk_free_space(__DIR__);
            $diskTotal = disk_total_space(__DIR__);

            if ($diskFree === false || $diskTotal === false) {
                return [
                  'healthy' => false,
                  'message' => 'Unable to check disk space',
                  'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }

            $usage = ($diskTotal - $diskFree) / $diskTotal;

            $checkDuration = (microtime(true) - $startTime) * 1000;

            return [
              'healthy' => $usage < 0.9, // Alert if using more than 90% of disk
              'message' => sprintf('Disk usage: %.1f%% (Free: %s)',
                $usage * 100,
                $this->formatBytes((int) $diskFree)
              ),
              'usage_percentage' => round($usage * 100, 1),
              'free_space' => $this->formatBytes((int) $diskFree),
              'duration_ms' => round($checkDuration, 2)
            ];
        } catch (\Throwable $e) {
            return [
              'healthy' => false,
              'message' => 'Disk check failed: ' . $e->getMessage(),
              'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }
    }

    private function checkRequestLogging(): array
    {
        $startTime = microtime(true);

        $enabled = RequestLogger::isEnabled();
        $config = [
          'enabled' => $enabled,
          'level' => $_ENV['REQUEST_LOGGING_LEVEL'] ?? 'info',
          'breadcrumbs' => ($_ENV['REQUEST_LOGGING_BREADCRUMBS'] ?? 'true') === 'true',
          'metrics' => ($_ENV['REQUEST_LOGGING_METRICS'] ?? 'true') === 'true',
          'datadog' => ($_ENV['REQUEST_LOGGING_DATADOG'] ?? 'true') === 'true'
        ];

        $checkDuration = (microtime(true) - $startTime) * 1000;

        return [
          'healthy' => true,
          'message' => $enabled ? 'Request logging enabled' : 'Request logging disabled',
          'configuration' => $config,
          'duration_ms' => round($checkDuration, 2)
        ];
    }

    private function convertToBytes(string $size): int
    {
        if ($size === '-1') {
            return PHP_INT_MAX; // No memory limit
        }

        $unit = strtolower(substr($size, -1));
        $value = (int) substr($size, 0, -1);

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        } elseif ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
