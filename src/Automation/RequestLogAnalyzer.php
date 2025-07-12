#!/usr/bin/env php
<?php

/**
 * Request Log Analyzer
 *
 * Usage: php analyze_request_logs.php [options] <log_file>
 *
 * Options:
 *   --request-id=<id>     Analyze specific request
 *   --slow-only           Show only slow requests
 *   --errors-only         Show only failed requests
 *   --summary             Show summary statistics
 *   --from=<datetime>     Start date (e.g., "2024-01-01 00:00:00")
 *   --to=<datetime>       End date
 *   --endpoint=<pattern>  Filter by endpoint pattern
 */

class RequestLogAnalyzer
{
    private array $requests = [];
    private array $options = [];

    public function __construct(array $options)
    {
        $this->options = $options;
    }

    public function analyze(string $logFile): void
    {
        if (!file_exists($logFile)) {
            die("Error: Log file not found: $logFile\n");
        }

        echo "Analyzing log file: $logFile\n";
        echo str_repeat('-', 80) . "\n";

        $this->parseLogFile($logFile);

        if (isset($this->options['request-id'])) {
            $this->showRequestDetails($this->options['request-id']);
        } elseif (isset($this->options['summary'])) {
            $this->showSummary();
        } else {
            $this->showRequests();
        }
    }

    private function parseLogFile(string $logFile): void
    {
        $handle = fopen($logFile, 'r');
        if (!$handle) {
            die("Error: Cannot open log file\n");
        }

        while (($line = fgets($handle)) !== false) {
            // Parse REQUEST_START
            if (strpos($line, 'REQUEST_START:') !== false) {
                $this->parseRequestStart($line);
            }
            // Parse REQUEST_END
            elseif (strpos($line, 'REQUEST_END:') !== false) {
                $this->parseRequestEnd($line);
            }
            // Parse BREADCRUMB
            elseif (strpos($line, 'BREADCRUMB:') !== false && isset($this->options['request-id'])) {
                $this->parseBreadcrumb($line);
            }
            // Parse REQUEST_ERROR
            elseif (strpos($line, 'REQUEST_ERROR:') !== false) {
                $this->parseRequestError($line);
            }
            // Parse SLOW_REQUEST
            elseif (strpos($line, 'SLOW_REQUEST:') !== false) {
                $this->parseSlowRequest($line);
            }
        }

        fclose($handle);
    }

    private function parseRequestStart($line): void
    {
        if (preg_match('/REQUEST_START: (.+)$/', $line, $matches)) {
            $data = json_decode($matches[1], true);
            if ($data && isset($data['request_id'])) {
                $this->requests[$data['request_id']] = [
                  'id' => $data['request_id'],
                  'endpoint' => $data['endpoint'] ?? 'unknown',
                  'method' => $data['method'] ?? 'unknown',
                  'user' => $data['user'] ?? 'unknown',
                  'timestamp' => $data['timestamp'] ?? '',
                  'breadcrumbs' => [],
                  'errors' => [],
                  'metrics' => []
                ];
            }
        }
    }

    private function parseRequestEnd($line): void
    {
        if (preg_match('/REQUEST_END: (.+)$/', $line, $matches)) {
            $data = json_decode($matches[1], true);
            if ($data && isset($data['request_id']) && isset($this->requests[$data['request_id']])) {
                $this->requests[$data['request_id']] = array_merge(
                  $this->requests[$data['request_id']],
                  [
                    'duration_ms' => $data['duration_ms'] ?? 0,
                    'success' => $data['success'] ?? false,
                    'memory_peak_mb' => $data['memory_peak_mb'] ?? 0,
                    'breadcrumb_count' => $data['breadcrumb_count'] ?? 0,
                    'metric_count' => $data['metric_count'] ?? 0,
                    'performance_breakdown' => $data['performance_breakdown'] ?? []
                  ]
                );
            }
        }
    }

    private function parseBreadcrumb($line): void
    {
        if (preg_match('/BREADCRUMB: (.+)$/', $line, $matches)) {
            $data = json_decode($matches[1], true);
            if ($data && isset($data['request_id']) && isset($this->requests[$data['request_id']])) {
                $this->requests[$data['request_id']]['breadcrumbs'][] = $data;
            }
        }
    }

    private function parseRequestError($line): void
    {
        if (preg_match('/REQUEST_ERROR: (.+)$/', $line, $matches)) {
            $data = json_decode($matches[1], true);
            if ($data && isset($data['request_id']) && isset($this->requests[$data['request_id']])) {
                $this->requests[$data['request_id']]['errors'][] = $data;
            }
        }
    }

    private function parseSlowRequest($line): void
    {
        if (preg_match('/SLOW_REQUEST: (.+)$/', $line, $matches)) {
            $data = json_decode($matches[1], true);
            if ($data && isset($data['request_id']) && isset($this->requests[$data['request_id']])) {
                $this->requests[$data['request_id']]['is_slow'] = true;
                $this->requests[$data['request_id']]['slowest_operations'] = $data['slowest_operations'] ?? [];
            }
        }
    }

    private function showRequests(): void
    {
        $requests = $this->filterRequests();

        if (empty($requests)) {
            echo "No requests found matching criteria.\n";
            return;
        }

        // Sort by duration descending
        usort($requests, fn($a, $b) => ($b['duration_ms'] ?? 0) <=> ($a['duration_ms'] ?? 0));

        echo sprintf("%-15s %-40s %-10s %-10s %-10s %s\n",
          "Request ID", "Endpoint", "Method", "Duration", "Status", "User"
        );
        echo str_repeat('-', 100) . "\n";

        foreach ($requests as $request) {
            $status = ($request['success'] ?? false) ? 'SUCCESS' : 'FAILED';
            $status = isset($request['is_slow']) ? 'SLOW' : $status;

            echo sprintf("%-15s %-40s %-10s %8.2fms %-10s %s\n",
              substr($request['id'], 0, 15),
              substr($request['endpoint'], 0, 40),
              $request['method'],
              $request['duration_ms'] ?? 0,
              $status,
              $request['user']
            );
        }
    }

    private function showRequestDetails(string $requestId): void
    {
        if (!isset($this->requests[$requestId])) {
            echo "Request not found: $requestId\n";
            return;
        }

        $request = $this->requests[$requestId];

        echo "Request Details\n";
        echo "===============\n";
        echo "ID: " . $request['id'] . "\n";
        echo "Endpoint: " . $request['endpoint'] . "\n";
        echo "Method: " . $request['method'] . "\n";
        echo "User: " . $request['user'] . "\n";
        echo "Timestamp: " . $request['timestamp'] . "\n";
        echo "Duration: " . ($request['duration_ms'] ?? 'N/A') . " ms\n";
        echo "Success: " . (($request['success'] ?? false) ? 'Yes' : 'No') . "\n";
        echo "Memory Peak: " . ($request['memory_peak_mb'] ?? 'N/A') . " MB\n";
        echo "\n";

        if (!empty($request['performance_breakdown'])) {
            echo "Performance Breakdown\n";
            echo "====================\n";
            foreach ($request['performance_breakdown'] as $step) {
                echo sprintf("  %-50s %8.2f ms (total: %8.2f ms)\n",
                  $step['step'],
                  $step['duration_ms'],
                  $step['cumulative_ms']
                );
            }
            echo "\n";
        }

        if (!empty($request['errors'])) {
            echo "Errors\n";
            echo "======\n";
            foreach ($request['errors'] as $error) {
                echo "  - " . $error['message'] . "\n";
            }
            echo "\n";
        }

        if (!empty($request['breadcrumbs'])) {
            echo "Breadcrumbs\n";
            echo "===========\n";
            foreach ($request['breadcrumbs'] as $breadcrumb) {
                echo sprintf("  [%8.2f ms] %-15s %s\n",
                  $breadcrumb['elapsed_ms'] ?? 0,
                  $breadcrumb['component'] ?? 'unknown',
                  $breadcrumb['message'] ?? ''
                );
            }
        }
    }

    private function showSummary(): void
    {
        $requests = $this->filterRequests();

        $total = count($requests);
        $successful = count(array_filter($requests, fn($r) => $r['success'] ?? false));
        $failed = $total - $successful;
        $slow = count(array_filter($requests, fn($r) => isset($r['is_slow'])));

        $durations = array_map(fn($r) => $r['duration_ms'] ?? 0, $requests);
        $avgDuration = $total > 0 ? array_sum($durations) / $total : 0;
        $maxDuration = $total > 0 ? max($durations) : 0;
        $minDuration = $total > 0 ? min($durations) : 0;

        // Group by endpoint
        $endpoints = [];
        foreach ($requests as $request) {
            $endpoint = $request['endpoint'];
            if (!isset($endpoints[$endpoint])) {
                $endpoints[$endpoint] = ['count' => 0, 'duration' => 0, 'errors' => 0];
            }
            $endpoints[$endpoint]['count']++;
            $endpoints[$endpoint]['duration'] += $request['duration_ms'] ?? 0;
            if (!($request['success'] ?? false)) {
                $endpoints[$endpoint]['errors']++;
            }
        }

        echo "Summary Statistics\n";
        echo "==================\n";
        echo "Total Requests: $total\n";
        echo "Successful: $successful (" . ($total > 0 ? round($successful / $total * 100, 1) : 0) . "%)\n";
        echo "Failed: $failed (" . ($total > 0 ? round($failed / $total * 100, 1) : 0) . "%)\n";
        echo "Slow: $slow (" . ($total > 0 ? round($slow / $total * 100, 1) : 0) . "%)\n";
        echo "\n";
        echo "Duration Statistics\n";
        echo "==================\n";
        echo sprintf("Average: %.2f ms\n", $avgDuration);
        echo sprintf("Min: %.2f ms\n", $minDuration);
        echo sprintf("Max: %.2f ms\n", $maxDuration);
        echo "\n";
        echo "Top Endpoints by Volume\n";
        echo "=======================\n";

        // Sort endpoints by count
        uasort($endpoints, fn($a, $b) => $b['count'] <=> $a['count']);

        $top = 0;
        foreach ($endpoints as $endpoint => $stats) {
            if (++$top > 10) break;
            $avgEndpointDuration = $stats['count'] > 0 ? $stats['duration'] / $stats['count'] : 0;
            echo sprintf("%-40s %5d requests, %8.2f ms avg, %d errors\n",
              substr($endpoint, 0, 40),
              $stats['count'],
              $avgEndpointDuration,
              $stats['errors']
            );
        }
    }

    private function filterRequests(): array
    {
        $filtered = $this->requests;

        // Filter by date range
        if (isset($this->options['from'])) {
            $from = strtotime($this->options['from']);
            $filtered = array_filter($filtered, fn($r) => strtotime($r['timestamp'] ?? '') >= $from);
        }

        if (isset($this->options['to'])) {
            $to = strtotime($this->options['to']);
            $filtered = array_filter($filtered, fn($r) => strtotime($r['timestamp'] ?? '') <= $to);
        }

        // Filter by endpoint
        if (isset($this->options['endpoint'])) {
            $pattern = $this->options['endpoint'];
            $filtered = array_filter($filtered, fn($r) => fnmatch($pattern, $r['endpoint']));
        }

        // Filter slow only
        if (isset($this->options['slow-only'])) {
            $filtered = array_filter($filtered, fn($r) => isset($r['is_slow']));
        }

        // Filter errors only
        if (isset($this->options['errors-only'])) {
            $filtered = array_filter($filtered, fn($r) => !($r['success'] ?? false));
        }

        return $filtered;
    }
}

// Parse command line options
$options = getopt('', [
  'request-id:',
  'slow-only',
  'errors-only',
  'summary',
  'from:',
  'to:',
  'endpoint:',
  'help'
]);

if (isset($options['help']) || $argc < 2) {
    echo "Request Log Analyzer\n";
    echo "Usage: php analyze_request_logs.php [options] <log_file>\n\n";
    echo "Options:\n";
    echo "  --request-id=<id>     Analyze specific request\n";
    echo "  --slow-only           Show only slow requests\n";
    echo "  --errors-only         Show only failed requests\n";
    echo "  --summary             Show summary statistics\n";
    echo "  --from=<datetime>     Start date (e.g., \"2024-01-01 00:00:00\")\n";
    echo "  --to=<datetime>       End date\n";
    echo "  --endpoint=<pattern>  Filter by endpoint pattern\n";
    echo "  --help                Show this help\n";
    exit(0);
}

$logFile = $argv[count($argv) - 1];
$analyzer = new RequestLogAnalyzer($options);
$analyzer->analyze($logFile);
