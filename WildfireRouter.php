<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;

class WildfireRouter
{
    private array $routes = [];
    private ?ContainerInterface $container = null;

    public function __construct(string $configPath, ?ContainerInterface $container = null)
    {
        if (!file_exists($configPath)) {
            throw new \InvalidArgumentException("Route config not found: {$configPath}");
        }

        $this->routes = require $configPath;
        $this->container = $container;
    }

    public function dispatch(string $method, string $path, mixed $requestData = []): array
    {
        $method = strtoupper($method);
        $routes = $this->routes[$method] ?? [];

        foreach ($routes as $route => $handler) {
            $pattern = $this->convertRouteToRegex($route, $paramNames);
            if (preg_match($pattern, $path, $matches)) {
                // Extract named parameters
                $params = [];
                foreach ($paramNames as $i => $name) {
                    $params[$name] = $matches[$i + 1];
                }

                try {
                    return $this->callHandler($handler, $requestData->data, $params);
                } catch (\Throwable $e) {
                    Log::error("Router error: " . $e->getMessage());
                    http_response_code(500);
                    return [
                      'error' => 'Internal server error',
                      'success' => false,
                      'data' => []
                    ];
                }
            }
        }

        // No route matched

        //TODO: once all legacy code is gone, uncomment these 2 lines and delete the error line
     //   http_response_code(404);
        return [
       //   'error' => "Route Not Found: {$method} {$path}",
          'error' => "Route Not Found",
          'success' => false,
          'data' => []
        ];
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    private function callHandler(mixed $handler, mixed $requestData, array $params): array
    {
        // Handle different handler formats
        if (is_array($handler)) {
            [$class, $methodName] = $handler;
            return $this->callClassMethod($class, $methodName, $requestData, $params);
        }

        if (is_callable($handler)) {
            return $handler($requestData, $params);
        }

        if (is_string($handler)) {
            return $this->callStringHandler($handler, $requestData, $params);
        }

        throw new \InvalidArgumentException('Invalid handler format');
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    private function callClassMethod(string $class, string $methodName, mixed $requestData, array $params): array
    {
        // Check if handler exists
        if (!class_exists($class) || !method_exists($class, $methodName)) {
            throw new \RuntimeException("Handler {$class}::{$methodName} not found");
        }

        // Try to get instance from DI container first
        if ($this->container && $this->container->has($class)) {
            $instance = $this->container->get($class);
            return $instance->$methodName($requestData, $params);
        }

        // Fall back to static call or new instance
        if (is_callable([$class, $methodName])) {
            return $class::$methodName($requestData, $params);
        }

        $instance = new $class();
        return $instance->$methodName($requestData, $params);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    private function callStringHandler(string $handler, mixed $requestData, array $params): array
    {
        // Handle "Class::method" format
        if (str_contains($handler, '::')) {
            [$class, $methodName] = explode('::', $handler, 2);
            return $this->callClassMethod($class, $methodName, $requestData, $params);
        }

        // Handle single class name (assume 'handler' method)
        return $this->callClassMethod($handler, 'handler', $requestData, $params);
    }

    private function convertRouteToRegex(string $route, ?array &$paramNames): string
    {
        $paramNames = [];
        // Escape slashes, convert {param} to capture groups
        $regex = preg_replace_callback(
          '/\{([^}]+)\}/',
          function ($m) use (&$paramNames) {
              $paramNames[] = $m[1];
              return '([^\/]+)';
          },
          preg_quote($route, '#')
        );

        return '#^' . $regex . '$#';
    }

    /**
     * Add a route dynamically
     */
    public function addRoute(string $method, string $path, mixed $handler): void
    {
        $method = strtoupper($method);
        $this->routes[$method] = $this->routes[$method] ?? [];
        $this->routes[$method][$path] = $handler;
    }

    /**
     * Get all registered routes
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}


//class WildfireRouter
//{
//    private array $routes = [];
//
//    public function __construct(string $configPath)
//    {
//        if (! file_exists($configPath)) {
//            throw new \InvalidArgumentException("Route config not found: {$configPath}");
//        }
//        $this->routes = require $configPath;
//    }
//
//    public function dispatch(string $method, string $path, mixed $requestData = []): array {
//        $method = strtoupper($method);
//        $routes = $this->routes[$method] ?? [];
//
//        foreach ($routes as $route => [$class, $methodName]) {
//            $pattern = $this->convertRouteToRegex($route, $paramNames);
//            if (preg_match($pattern, $path, $matches)) {
//                // Extract named parameters
//                $params = [];
//                foreach ($paramNames as $i => $name) {
//                    $params[$name] = $matches[$i + 1];
//                }
//
//                // Call handler
//                if (! class_exists($class) || ! method_exists($class, $methodName)) {
//                    http_response_code(500);
//                    return [
//                      'error' => "Handler {$class}::{$methodName} not found",
//                      'success' => false,
//                      'data' => []
//                    ];
//                }
//
//                // If static method, call directly; else instantiate
//                return is_callable([$class, $methodName])
//                  ? $class::$methodName($requestData, $params)
//                  : (new $class)->$methodName($requestData, $params);
//            }
//        }
//
//        // No match
//
//        // TODO: Re-enable the 404 once all legacy code is gone
//        // http_response_code(404);
//
//        return [
//          'error' => "Route Not Found",
//          'success' => false,
//          'data' => []
//        ];
//    }
//
//    private function convertRouteToRegex(string $route, ?array &$paramNames): string
//    {
//        $paramNames = [];
//        // escape slashes, convert {param} to capture groups
//        $regex = preg_replace_callback(
//          '/\{([^}]+)\}/',
//          function ($m) use (&$paramNames) {
//              $paramNames[] = $m[1];
//              return '([^\/]+)';
//          },
//          preg_quote($route, '#')
//        );
//
//        return '#^' . $regex . '$#';
//    }
//}
