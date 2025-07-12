<?php

declare(strict_types=1);

namespace WF\API\Automation\Factories;

use WF\API\Automation\Contracts\BureauClientInterface;
use WF\API\Automation\Clients\EquifaxClient;
use WF\API\Automation\Clients\ExperianClient;
use WF\API\Automation\Clients\TransUnionClient;
use WF\API\Automation\Exceptions\AutomationException;
use Psr\Container\ContainerInterface;

class BureauClientFactory
{
    public function __construct(
      protected ContainerInterface $container
    ) {}

    /**
     * @throws \WF\API\Automation\Exceptions\AutomationException
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function create(string $bureau): BureauClientInterface
    {
        return match (strtolower($bureau)) {
            'equifax' => $this->container->get(EquifaxClient::class),
            'experian' => $this->container->get(ExperianClient::class),
            'transunion', 'trans_union' => $this->container->get(TransUnionClient::class),
            default => throw new AutomationException("Unsupported bureau: {$bureau}")
        };
    }

    public function getAvailableBureaus(): array
    {
        $bureaus = [];

        foreach (['equifax', 'experian', 'transunion'] as $bureau) {
            try {
                $client = $this->create($bureau);
                if ($client->isAvailable()) {
                    $bureaus[] = $bureau;
                }
            } catch (\Throwable) {
                // Skip unavailable bureaus
            }
        }

        return $bureaus;
    }
}
