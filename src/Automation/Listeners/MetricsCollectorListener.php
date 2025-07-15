<?php

declare(strict_types=1);

namespace WF\API\Automation\Listeners;

use WF\API\Automation\Events\PreQualCompletedEvent;
use WF\API\Automation\Events\CreditReportPulledEvent;

class MetricsCollectorListener
{
    public function handlePreQualCompleted(PreQualCompletedEvent $event): void
    {
        // Send metrics to monitoring system (e.g., StatsD, Prometheus)
        $this->recordMetric('prequal.completed', 1, [
          'risk_tier' => $event->result->getRiskTier(),
          'bureau' => $event->creditProfile->bureau,
          'approved' => $event->result->isApproved() ? 'true' : 'false'
        ]);

        $this->recordMetric('prequal.processing_time',
          $event->metadata['processing_time'] ?? 0
        );
    }

    public function handleCreditReportPulled(CreditReportPulledEvent $event): void
    {
        $this->recordMetric('credit_report.pulled', 1, [
          'bureau' => $event->bureau,
          'from_cache' => $event->fromCache ? 'true' : 'false'
        ]);
    }

    private function recordMetric(string $metric, float $value, array $tags = []): void
    {
        // Implementation depends on your metrics system
        // Example with StatsD:
        // $this->statsd->gauge($metric, $value, 1.0, $tags);
    }
}