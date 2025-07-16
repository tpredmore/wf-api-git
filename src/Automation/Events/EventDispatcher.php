<?php

declare(strict_types=1);

namespace WF\API\Automation\Events;

use Log;

class EventDispatcher
{
    private array $listeners = [];
    private array $asyncListeners = [];
    private bool $enableAsync = false;

    public function __construct(bool $enableAsync = false)
    {
        $this->enableAsync = $enableAsync;
    }

    /**
     * Add a synchronous listener
     */
    public function addListener(string $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event][$priority][] = $listener;
        // Sort by priority
        if (isset($this->listeners[$event])) {
            krsort($this->listeners[$event]);
        }
    }

    /**
     * Add an async listener (processed after response)
     */
    public function addAsyncListener(string $event, callable $listener): void
    {
        $this->asyncListeners[$event][] = $listener;
    }

    /**
     * Dispatch an event to all listeners
     */
    public function dispatch(object $event): void
    {
        $eventClass = get_class($event);
        $startTime = microtime(true);

        // Process synchronous listeners
        $this->processListeners($eventClass, $event, $this->listeners);

        // Queue async listeners for processing after response
        if ($this->enableAsync && isset($this->asyncListeners[$eventClass])) {
            $this->queueAsyncListeners($eventClass, $event);
        }

        $duration = microtime(true) - $startTime;
        if ($duration > 0.1) { // Log slow event processing
            Log::warn("Slow event processing for $eventClass: {$duration}s");
        }
    }

    private function processListeners(string $eventClass, object $event, array $listeners): void
    {
        if (!isset($listeners[$eventClass])) {
            return;
        }

        foreach ($listeners[$eventClass] as $priority => $priorityListeners) {
            foreach ($priorityListeners as $listener) {
                try {
                    $listener($event);
                } catch (\Exception $e) {
                    Log::error("Event listener failed for $eventClass: " . $e->getMessage(), [
                      'event' => $eventClass,
                      'listener' => $this->getListenerName($listener),
                      'exception' => $e
                    ]);

                    // Don't let one listener failure stop others
                    continue;
                }
            }
        }
    }

    private function queueAsyncListeners(string $eventClass, object $event): void
    {
        // In a real implementation, this would queue to Redis/RabbitMQ
        // For now, we'll use register_shutdown_function
        register_shutdown_function(function() use ($eventClass, $event) {
            $this->processListeners($eventClass, $event, $this->asyncListeners);
        });
    }

    private function getListenerName(callable $listener): string
    {
        if (is_array($listener)) {
            return get_class($listener[0]) . '::' . $listener[1];
        }
        if (is_string($listener)) {
            return $listener;
        }
        return 'Closure';
    }

    /**
     * Get all registered listeners for debugging
     */
    public function getListeners(): array
    {
        return [
          'sync' => $this->listeners,
          'async' => $this->asyncListeners
        ];
    }
}
