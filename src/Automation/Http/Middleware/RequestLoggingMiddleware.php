<?php

declare(strict_types=1);

namespace WF\API\Automation\Http\Middleware;

use WF\API\Automation\Services\RequestLogger;

class RequestLoggingMiddleware
{
    /**
     * Handle the request and automatically manage logging lifecycle
     */
    public function handle($request, callable $next)
    {
        // Extract request details
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $endpoint = $request->uri ?? $_SERVER['REQUEST_URI'] ?? 'unknown';
        $headers = $this->getRequestHeaders();

        // Parse request data
        $requestData = null;
        if (is_object($request) && property_exists($request, 'data')) {
            $requestData = json_decode($request->data, true);
        }

        // Start request logging
        $requestId = RequestLogger::startRequest($endpoint, $method, $headers, $requestData);

        // Set initial context
        RequestLogger::setContext('endpoint', $endpoint);
        RequestLogger::setContext('method', $method);

        // Add authorization context if available
        if (isset($headers['X-Gravity-User'])) {
            RequestLogger::setContext('user', $headers['X-Gravity-User']);
        }

        try {
            // Add breadcrumb for routing
            RequestLogger::addBreadcrumb('Request routed', [
              'handler' => $this->getHandlerInfo($endpoint)
            ], 'router');

            // Execute the actual request handler
            $startTime = microtime(true);
            $response = $next($request);
            $handlerDuration = microtime(true) - $startTime;

            // Add metric for handler execution time
            RequestLogger::addMetric('handler.duration', $handlerDuration * 1000, [
              'endpoint' => $endpoint
            ]);

            // Check if response indicates success
            $success = $this->isSuccessResponse($response);
            $error = $success ? null : $this->extractErrorMessage($response);

            // End request logging
            RequestLogger::endRequest($success, $response, $error);

            return $response;

        } catch (\Throwable $e) {
            // Log the exception with full context
            RequestLogger::logException($e, [
              'endpoint' => $endpoint,
              'method' => $method
            ]);

            // End request as failure
            RequestLogger::endRequest(false, null, $e->getMessage());

            // Re-throw the exception
            throw $e;
        }
    }

    /**
     * Get all request headers
     */
    private function getRequestHeaders(): array
    {
        $headers = [];

        // Get headers from $_SERVER
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) === 'HTTP_') {
                $headerName = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$headerName] = $value;
            }
        }

        // Add custom headers
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }

    /**
     * Get handler info from endpoint
     */
    private function getHandlerInfo(string $endpoint): string
    {
        // Extract the main API module from the endpoint
        $parts = explode('/', trim($endpoint, '/'));

        if (count($parts) >= 2) {
            return $parts[0] . '/' . $parts[1];
        }

        return $endpoint;
    }

    /**
     * Check if response indicates success
     */
    private function isSuccessResponse($response): bool
    {
        if (is_array($response)) {
            return $response['success'] ?? false;
        }

        if (is_object($response) && property_exists($response, 'success')) {
            return $response->success;
        }

        // Check HTTP response code if set
        $httpCode = http_response_code();
        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Extract error message from response
     */
    private function extractErrorMessage($response): ?string
    {
        if (is_array($response) && isset($response['error'])) {
            return $response['error'];
        }

        if (is_object($response) && property_exists($response, 'error')) {
            return $response->error;
        }

        return null;
    }
}
