<?php

declare(strict_types=1);

// --------------------------------------------------
// bootstrap.php
// --------------------------------------------------

use DI\ContainerBuilder;

$container = '';
$builder = new ContainerBuilder();
$builder->addDefinitions(__DIR__ . '/config/di.php');
// (Optional) Enable compilation for production:
// $builder->enableCompilation(__DIR__ . '/var/cache');

try {
    $container = $builder->build();
} catch (Exception $e) {
    Log::error('Failed to instantiate the container service: ' . $e->getMessage());
}

return $container;
