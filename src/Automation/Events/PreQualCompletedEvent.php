<?php

declare(strict_types=1);

namespace WF\API\Automation\Events;

use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Models\Vehicle;
use WF\API\Automation\Models\CreditProfile;
use WF\API\Automation\Models\PreQualResult;

/**
 * Base event class
 */
abstract class Event
{
    public readonly string $eventId;
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct()
    {
        $this->eventId = uniqid('evt_', true);
        $this->occurredAt = new \DateTimeImmutable();
    }
}

/**
 * Fired when pre-qualification process starts
 */
class PreQualStartedEvent extends Event
{
    public function __construct(
      public readonly Applicant $applicant,
      public readonly Vehicle $vehicle,
      public readonly string $preferredBureau,
      public readonly array $metadata = []
    ) {
        parent::__construct();
    }
}

/**
 * Fired when credit report is pulled successfully
 */
class CreditReportPulledEvent extends Event
{
    public function __construct(
      public readonly Applicant $applicant,
      public readonly CreditProfile $creditProfile,
      public readonly string $bureau,
      public readonly bool $fromCache,
      public readonly array $metadata = []
    ) {
        parent::__construct();
    }
}

/**
 * Fired when pre-qualification is completed
 */
class PreQualCompletedEvent extends Event
{
    public function __construct(
      public readonly Applicant $applicant,
      public readonly Vehicle $vehicle,
      public readonly CreditProfile $creditProfile,
      public readonly PreQualResult $result,
      public readonly array $metadata = []
    ) {
        parent::__construct();
    }
}

/**
 * Fired when pre-qualification fails
 */
class PreQualFailedEvent extends Event
{
    public function __construct(
      public readonly Applicant $applicant,
      public readonly Vehicle $vehicle,
      public readonly string $reason,
      public readonly ?\Throwable $exception = null,
      public readonly array $metadata = []
    ) {
        parent::__construct();
    }
}

/**
 * Fired when vehicle valuation is completed
 */
class VehicleValuationCompletedEvent extends Event
{
    public function __construct(
      public readonly Vehicle $vehicle,
      public readonly array $valuationData,
      public readonly string $provider,
      public readonly array $metadata = []
    ) {
        parent::__construct();
    }
}