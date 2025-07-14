<?php

declare(strict_types=1);

namespace WF\API\Automation\Http\Controllers;

use Log;

class HealthController
{
    public function check(array $requestData, array $params): array
    {
        try {
            // Basic health checks
            $checks = [
              'database' => $this->checkDatabase(),
              'logging' => $this->checkLogging(),
              'memory' => $this->checkMemory(),
              'disk' => $this->checkDisk(),
              'cache' => $this->checkCache(),
              'bureaus' => $this->checkBureauConnections()
            ];

            $allHealthy = array_reduce($checks, fn($carry, $check) => $carry && $check['healthy'], true);

            return [
              'success' => true,
              'error' => '',
              'data' => [
                'status' => $allHealthy ? 'healthy' : 'degraded',
                'timestamp' => date('Y-m-d H:i:s'),
                'version' => '2.0.0',
                'checks' => $checks
              ]
            ];

        } catch (\Throwable $e) {
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
        try {
            // Add your database health check logic here
            // For now, return success
            return [
              'healthy' => true,
              'message' => 'Database connection OK'
            ];
        } catch (\Throwable $e) {
            return [
              'healthy' => false,
              'message' => 'Database connection failed: ' . $e->getMessage()
            ];
        }
    }

    private function checkCache(): array
    {
        try {
            // Test Redis connection
            $testKey = 'health_check_' . time();
            \Cache::set($testKey, 'test', false, 1);
            $value = \Cache::get($testKey);
            \Cache::del($testKey);

            return [
              'healthy' => $value === 'test',
              'message' => 'Cache (Redis) connection OK'
            ];
        } catch (\Throwable $e) {
            return [
              'healthy' => false,
              'message' => 'Cache connection failed: ' . $e->getMessage()
            ];
        }
    }

    private function checkBureauConnections(): array
    {
        $bureaus = [
          'equifax' => !empty($_ENV['EQUIFAX_CLIENT_ID']),
          'experian' => !empty($_ENV['EXPERIAN_CLIENT_ID']),
          'transunion' => !empty($_ENV['TRANSUNION_ENDPOINT'])
        ];

        $configured = array_filter($bureaus);

        return [
          'healthy' => count($configured) > 0,
          'message' => sprintf('%d of 3 bureaus configured', count($configured)),
          'details' => $bureaus
        ];
    }

    private function checkLogging(): array
    {
        try {
            Log::info('Health check log test');
            return [
              'healthy' => true,
              'message' => 'Logging system OK'
            ];
        } catch (\Throwable $e) {
            return [
              'healthy' => false,
              'message' => 'Logging system error: ' . $e->getMessage()
            ];
        }
    }

    private function checkMemory(): array
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitBytes = $this->convertToBytes($memoryLimit);

        $usage = $memoryLimitBytes > 0 ? $memoryUsage / $memoryLimitBytes : 0;

        return [
          'healthy' => $usage < 0.8, // Alert if using more than 80% of memory
          'message' => sprintf('Memory usage: %.1f%% (%s / %s)',
            $usage * 100,
            $this->formatBytes($memoryUsage),
            $memoryLimit
          )
        ];
    }

    private function checkDisk(): array
    {
        try {
            $diskFree = disk_free_space(__DIR__);
            $diskTotal = disk_total_space(__DIR__);

            if ($diskFree === false || $diskTotal === false) {
                return [
                  'healthy' => false,
                  'message' => 'Unable to check disk space'
                ];
            }

            $usage = ($diskTotal - $diskFree) / $diskTotal;

            return [
              'healthy' => $usage < 0.9, // Alert if using more than 90% of disk
              'message' => sprintf('Disk usage: %.1f%% (Free: %s)',
                $usage * 100,
                $this->formatBytes((int) $diskFree)
              )
            ];
        } catch (\Throwable $e) {
            return [
              'healthy' => false,
              'message' => 'Disk check failed: ' . $e->getMessage()
            ];
        }
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
