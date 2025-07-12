<?php
declare(strict_types=1);

// ────────────────────────────────────────────────────
// 1) Bootstrap
// ────────────────────────────────────────────────────

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/includes.php';

// Load constants into $_ENV
loadWildFireEnvironment();

// Set timezone
date_default_timezone_set($_ENV['TIMEZONE'] ?? 'America/Chicago');

$log_app_name = 'api';
$log_facility = LOG_LOCAL2;
$result = null;
$success = false;
$err = '';
$data = null;

//  Load the DI container.
$container = require __DIR__ . '/bootstrap.php';

// Create router with DI container
$router = new WildfireRouter(__DIR__ . '/config/routes.php', $container);

// ────────────────────────────────────────────────────
// 2) Helpers
// ────────────────────────────────────────────────────
function abort(int $code): never
{
    http_response_code($code);
    exit;
}

function isValidToken(?string $token): bool
{
    return in_array($token, [$_ENV['PROXY_AUTH_KEY'], $_ENV['WEB_AUTH_KEY']], true);
}

function loadWildFireEnvironment(): void {
    $reflection = new ReflectionClass('WildFire');
    $constants = $reflection->getConstants();

    foreach ($constants as $name => $value) {
        $_ENV[$name] = $value;
    }
}

// ────────────────────────────────────────────────────
// 3) Method & Auth checks
// ────────────────────────────────────────────────────
if (! $request->isPost()) {
    abort(405);
}

$token       = $request->header('X-Gravity-Auth-Token');
$current_user = $request->header('X-Gravity-User');

if (! isValidToken($token)) {
    abort(401);
}

if ($token === $_ENV['WEB_AUTH_KEY']
  && ! str_ends_with($current_user ?? '', '@gravitylending.com')
) {
    Log::error(
      "Invalid user: {$current_user}\n" .
      "URI: {$request->uri()}\n" .
      'Payload: ' . $request->content() . "\n" .
      'Headers: ' . print_r($request->headers(), true)
    );
    abort(401);
}

// ────────────────────────────────────────────────────
// 4) URI & Version validation
// ────────────────────────────────────────────────────
$uri = $request->uri();
if (
  empty($uri)
  || $uri === '/'
  || $request->hasScripts()
 // || ! file_exists( $uri . '.php')
) {
    abort(404);
}

if (($ver = $request->header('X-Gravity-Api-Version')) !== null) {
    $parts = explode('.', $ver);
    if (count($parts) > 3 || array_filter($parts, 'is_numeric') !== $parts) {
        abort(409);
    }
}

try {
    // ────────────────────────────────────────────────────
    // 5) Content‐Type & Payload
    // ────────────────────────────────────────────────────
    $ctype = $request->serverVar('CONTENT_TYPE') ?? '';
    $format = match (strtolower($ctype)) {
        'text/xml', 'application/xml' => 'xml',
        default                         => 'json',
    };

    $content = trim($request->content() ?: '');
    $json    = $content === '' ? null : json_decode($content, false);

    $has_options     = $json->options ?? null;
    $no_wait      = $has_options->no_wait      ?? false;
    $synchronous        = $has_options->synchronous ?? false;
    if ($synchronous) {
        $no_wait = false;
    }

    header('Content-Type: ' . ($format === 'xml' ? 'application/xml' : 'application/json'));

    // ────────────────────────────────────────────────────
    // 6) Route dispatch
    // ────────────────────────────────────────────────────

    // Get request information
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

      // Dispatch request
    $result = $router->dispatch($method, $uri, $json);

    $data = $result['data'];
    $err = $result['error'];
    $success = $result['success'];

    // TODO: Can remove this once all legacy code has been refactored
      if($err == 'Route Not Found') {
          // Run legacy code
          if (file_exists($uri . '.php')) {
              $err = null;
              require_once("$uri.php");
          } else {
              abort(404);
          }
      }

    // ────────────────────────────────────────────────────
    // 7) Build & emit response
    // ────────────────────────────────────────────────────
    $output = $data ?? null
      ? ['success' => $success, 'data' => $data, 'error' => $err]
      : ['success' => $success, 'error' => $err];

    if ($format === 'xml') {
        $xml = new SimpleXMLElement('<Gravity/>');
        json_to_xml($output, $xml);
        echo $xml->asXML();
    } else {
        echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    }
} catch (Throwable $ex) {
    Log::error($ex);
    $err = $ex->getMessage();
}
