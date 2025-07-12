<?php

namespace WF\API\Automation\Clients;

use WF\API\Automation\Contracts\BureauClientInterface;
use WF\API\Automation\Services\BureauCacheService;

class CachedBureauClient implements BureauClientInterface
{
    public function __construct(
      private BureauClientInterface $bureauClient,
      private BureauCacheService $cacheService,
      private bool $useCache = true,
      private ?int $cacheTtl = null
    ) {}

    public function pullCreditReport(array $consumers): array
    {
        if (!$this->useCache || empty($consumers[0]['ssn'])) {
            return $this->bureauClient->pullCreditReport($consumers);
        }

        $ssn = $consumers[0]['ssn'];
        $bureau = strtolower($this->bureauClient->getName());

        // Try cache first
        $cachedProfile = $this->cacheService->get($ssn, $bureau);
        if ($cachedProfile !== null) {
            // Return in the format expected by the parser
            return [
              'cached' => true,
              'bureau' => $bureau,
              'profile' => $cachedProfile
            ];
        }

        // Pull from bureau
        // Cache the response after parsing (would be done in PreQualEngine)
        // The caching happens after parsing in the engine

        return $this->bureauClient->pullCreditReport($consumers);
    }

    public function getName(): string
    {
        return $this->bureauClient->getName();
    }

    public function isAvailable(): bool
    {
        return $this->bureauClient->isAvailable();
    }
}