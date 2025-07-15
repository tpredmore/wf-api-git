<?php

namespace WF\API\Automation\Events;


/**
 * Service-level events
 */
class ServiceRequestEvent
{
    public function __construct(
      public readonly string $method,
      public readonly array $requestData,
      public readonly string $requestId,
      public readonly \DateTimeImmutable $timestamp = new \DateTimeImmutable()
    ) {}
}
