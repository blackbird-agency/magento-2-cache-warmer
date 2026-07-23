<?php

declare(strict_types=1);

namespace Blackbird\CacheWarmer\Api;

use GuzzleHttp\Exception\GuzzleException;

/**
 * Interface for the cache warmer service
 *
 * This interface defines methods for warming URLs in the cache.
 */
interface WarmerInterface
{
    /**
     * Warm a list of URLs in the cache
     *
     * This method crawls the provided URLs to warm the cache. It handles authentication,
     * concurrency, and logging of the results.
     *
     * @param string[] $urls List of URLs to warm
     * @return array<string, array{
     *     urls: string[],
     *     statuses: array<int, int>,
     *     durations: array<int, float>,
     *     total: int
     * }> Results of the warming process, indexed by crawl pool
     * @throws GuzzleException If there's an error with the HTTP client
     */
    public function warmUrls(array $urls): array;
}
