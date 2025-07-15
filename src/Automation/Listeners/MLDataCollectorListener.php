<?php

declare(strict_types=1);

namespace WF\API\Automation\Listeners;

use WF\API\Automation\Events\Event;
use WF\API\Automation\Events\PreQualCompletedEvent;
use WF\API\Automation\Events\CreditReportPulledEvent;
use WF\API\Automation\Events\PreQualFailedEvent;
use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Services\CreditDataCollectionService;
use Log;

class MLDataCollectorListener
{
    private bool $enabled;
    private float $sampleRate;

    public function __construct(
      private CreditDataCollectionService $collectionService,
      ?bool $enabled = null,
      ?float $sampleRate = null
    ) {
        $this->enabled = $enabled ?? ($_ENV['ML_COLLECTION_ENABLED'] ?? true);
        $this->sampleRate = $sampleRate ?? ((float)($_ENV['ML_COLLECTION_SAMPLE_RATE'] ?? 1.0));
    }

    /**
     * Handle successful pre-qualification
     */
    public function handlePreQualCompleted(PreQualCompletedEvent $event): void
    {
        if (!$this->shouldCollect($event)) {
            return;
        }

        try {
            Log::info("Collecting ML data for completed pre-qual", [
              'event_id' => $event->eventId,
              'applicant_name' => $event->applicant->getFullName(),
              'risk_tier' => $event->result->getRiskTier()
            ]);

            $this->collectionService->collectAndStore(
              $event->applicant,
              $event->creditProfile,
              $event->result,
              array_merge($event->metadata, [
                'event_id' => $event->eventId,
                'event_type' => 'prequal_completed',
                'timestamp' => $event->occurredAt->format('Y-m-d H:i:s'),
                'vehicle_vin' => $event->vehicle->vin,
                'loan_amount' => $event->vehicle->loanAmount
              ])
            );

            Log::info("ML data collected successfully", [
              'event_id' => $event->eventId
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to collect ML data", [
              'event_id' => $event->eventId,
              'error' => $e->getMessage(),
              'trace' => $e->getTraceAsString()
            ]);

            // Could dispatch another event here for monitoring
            // $this->dispatcher->dispatch(new MLDataCollectionFailedEvent($event, $e));
        }
    }

    /**
     * Handle credit report pulled (for partial data collection)
     */
    public function handleCreditReportPulled(CreditReportPulledEvent $event): void
    {
        if (!$this->shouldCollect($event)) {
            return;
        }

        try {
            Log::info("Collecting credit report data", [
              'event_id' => $event->eventId,
              'bureau' => $event->bureau,
              'from_cache' => $event->fromCache
            ]);

            // Store just the credit data for later analysis
            // This could be useful for understanding credit patterns
            // even when pre-qual doesn't complete

        } catch (\Exception $e) {
            Log::error("Failed to collect credit report data", [
              'event_id' => $event->eventId,
              'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle failed pre-qualification (for failure analysis)
     */
    public function handlePreQualFailed(PreQualFailedEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            Log::info("Collecting failure data for ML", [
              'event_id' => $event->eventId,
              'reason' => $event->reason
            ]);

            // Store failure data for understanding why pre-quals fail
            // This is valuable for improving the system

        } catch (\Exception $e) {
            Log::error("Failed to collect failure data", [
              'event_id' => $event->eventId,
              'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Determine if we should collect data for this event
     */
    private function shouldCollect(Event $event): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Check sample rate
        if ($this->sampleRate < 1.0) {
            $random = mt_rand() / mt_getrandmax();
            if ($random > $this->sampleRate) {
                Log::debug("Skipping ML collection due to sample rate", [
                  'event_id' => $event->eventId,
                  'sample_rate' => $this->sampleRate,
                  'random' => $random
                ]);
                return false;
            }
        }

        // Additional filtering logic
        if ($event instanceof PreQualCompletedEvent) {
            // Only collect complete results
            if (!$event->result->isComplete) {
                return false;
            }

            // Skip test data
            if ($this->isTestData($event->applicant)) {
                return false;
            }
        }

        return true;
    }

    private function isTestData(Applicant $applicant): bool
    {
        $testPatterns = [
          'test', 'demo', 'sample', 'example',
          '000000000', '111111111', '123456789'
        ];

        foreach ($testPatterns as $pattern) {
            if (stripos($applicant->firstName, $pattern) !== false ||
              stripos($applicant->lastName, $pattern) !== false ||
              $applicant->ssn === $pattern) {
                return true;
            }
        }

        return false;
    }
}
