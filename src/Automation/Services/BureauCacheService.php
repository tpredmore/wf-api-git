<?php

declare(strict_types=1);

namespace WF\API\Automation\Services;

use WF\API\Automation\Models\CreditProfile;
use Cache;
use Log;

class BureauCacheService
{
    private const CACHE_PREFIX = 'bureau:';
    private const DEFAULT_TTL = 1440; // 24 hours in minutes

    /**
     * Get cached credit profile
     */
    public function get(string $ssn, string $bureau): ?CreditProfile
    {
        $cacheKey = $this->buildCacheKey($ssn, $bureau);

        if (!Cache::has($cacheKey)) {
            return null;
        }

        try {
            $cachedData = Cache::get($cacheKey, true); // Get as array

            if (!is_array($cachedData)) {
                Log::warn("Invalid cache data for key: $cacheKey");
                Cache::del($cacheKey);
                return null;
            }

            // Check expiration
            if ($this->isExpired($cachedData)) {
                Cache::del($cacheKey);
                return null;
            }

            Log::info("Bureau cache hit for $bureau");

            return CreditProfile::fromArray($cachedData['profile']);

        } catch (\Throwable $e) {
            Log::error("Failed to retrieve from cache: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Cache credit profile
     */
    public function set(string $ssn, string $bureau, CreditProfile $profile, ?int $ttl = null): bool
    {
        $cacheKey = $this->buildCacheKey($ssn, $bureau);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        try {
            $cacheData = [
              'profile' => [
                'fico_score' => $profile->ficoScore,
                'bureau' => $profile->bureau,
                'open_trade_count' => $profile->openTradeCount,
                'auto_trade_count' => $profile->autoTradeCount,
                'derogatory_marks' => $profile->derogatoryMarks,
                'bankruptcies' => $profile->bankruptcies,
                'revolving_utilization' => $profile->revolvingUtilization,
                'inquiries_6mo' => $profile->inquiriesSixMonths,
                'estimated_monthly_debt' => $profile->estimatedMonthlyDebt,
                'trade_lines' => $profile->tradeLines,
                'score_factors' => $profile->scoreFactors,
                'hit' => $profile->hasHit,
                'pulled_at' => $profile->pulledAt ?? date('Y-m-d H:i:s')
              ],
              'cached_at' => time(),
              'expires_at' => time() + ($ttl * 60)
            ];

            $result = Cache::set($cacheKey, $cacheData, true, $ttl);

            if ($result) {
                Log::info("Cached bureau response for $bureau (TTL: $ttl minutes)");
            }

            return $result;

        } catch (\Throwable $e) {
            Log::error("Failed to cache bureau response: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear cache for specific applicant
     */
    public function clear(string $ssn, ?string $bureau = null): void
    {
        if ($bureau) {
            $cacheKey = $this->buildCacheKey($ssn, $bureau);
            Cache::del($cacheKey);
        } else {
            // Clear all bureaus for this SSN
            foreach (['equifax', 'experian', 'transunion'] as $b) {
                $cacheKey = $this->buildCacheKey($ssn, $b);
                Cache::del($cacheKey);
            }
        }
    }

    /**
     * Clear all bureau cache entries
     */
    public function clearAll(): void
    {
        Cache::bulk_delete(self::CACHE_PREFIX);
    }

    /**
     * Build cache key
     */
    private function buildCacheKey(string $ssn, string $bureau): string
    {
        // Hash SSN for privacy
        $hashedSsn = hash('sha256', $ssn);
        return self::CACHE_PREFIX . strtolower($bureau) . ':' . $hashedSsn;
    }

    /**
     * Check if cached data is expired
     */
    private function isExpired(array $data): bool
    {
        return !isset($data['expires_at']) || $data['expires_at'] < time();
    }
}
