<?php

declare(strict_types=1);

namespace WF\API\Automation\Clients;

use WF\API\Automation\Contracts\BureauClientInterface;
use Log;

abstract class AbstractBureauClient implements BureauClientInterface
{
    protected array $config;
    protected ?string $accessToken = null;
    protected ?\DateTimeImmutable $tokenExpiry = null;

    public function __construct(
      array $config = []
    ) {
        $this->config = $config;
    }

    abstract protected function authenticate(): string;
    abstract protected function buildRequestPayload(array $consumers): array;
    abstract protected function makeRequest(array $payload): array;

    public function pullCreditReport(array $consumers): array
    {
        $this->ensureValidToken();

        $payload = $this->buildRequestPayload($consumers);

        Log::info("Pulling credit report from {$this->getName()}, consumer count: " . count($consumers));

        return $this->makeRequest($payload);
    }

    public function isAvailable(): bool
    {
        try {
            $this->ensureValidToken();
            return !empty($this->accessToken);
        } catch (\Throwable $e) {
            Log::error("Bureau {$this->getName()} not available: " . $e->getMessage());
            return false;
        }
    }

    protected function ensureValidToken(): void
    {
        if ($this->isTokenValid()) {
            return;
        }

        $this->accessToken = $this->authenticate();
        $this->tokenExpiry = new \DateTimeImmutable('+50 minutes'); // Most tokens expire in 60 min

        Log::info("Obtained new token for {$this->getName()}, expires at " . $this->tokenExpiry->format('Y-m-d H:i:s'));
    }

    protected function isTokenValid(): bool
    {
        return !empty($this->accessToken)
          && $this->tokenExpiry !== null
          && $this->tokenExpiry > new \DateTimeImmutable();
    }
}
