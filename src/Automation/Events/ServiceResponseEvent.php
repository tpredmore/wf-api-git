<?php

namespace WF\API\Automation\Events;

class ServiceResponseEvent
{
    public function __construct(
      public readonly string $method,
      public readonly array $requestData,
      public readonly array $responseData,
      public readonly string $requestId,
      public readonly float $processingTime,
      public readonly \DateTimeImmutable $timestamp = new \DateTimeImmutable()
    ) {}
}