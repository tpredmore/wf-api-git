<?php

declare(strict_types=1);

namespace WF\API\Automation\Factories;

use WF\API\Automation\Contracts\ValuationProviderInterface;
use WF\API\Automation\Providers\KelleyBlueBookProvider;
use WF\API\Automation\Providers\NADAProvider;
use WF\API\Automation\Providers\JDPowerProvider;
use WF\API\Automation\Exceptions\ValuationException;
use Psr\Container\ContainerInterface;

class ValuationProviderFactory
{
    public function __construct(
      private ContainerInterface $container
    ) {}

    /**
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \WF\API\Automation\Exceptions\ValuationException
     */
    public function create(string $provider): ValuationProviderInterface
    {
        return match (strtolower($provider)) {
            'kbb', 'kelley_blue_book' => $this->container->get(KelleyBlueBookProvider::class),
            'nada' => $this->container->get(NADAProvider::class),
            'jdpower', 'jd_power' => $this->container->get(JDPowerProvider::class),
            default => throw new ValuationException("Unsupported valuation provider: {$provider}")
        };
    }

    /**
     * @throws \WF\API\Automation\Exceptions\ValuationException
     */
    public function createBest(): ValuationProviderInterface
    {
        // Try providers in order of preference
        $providers = ['kbb', 'nada', 'jdpower'];

        foreach ($providers as $provider) {
            try {
                $instance = $this->create($provider);
                if ($instance->isAvailable()) {
                    return $instance;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        throw new ValuationException('No valuation providers available');
    }
}
