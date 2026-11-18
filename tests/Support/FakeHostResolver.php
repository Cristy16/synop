<?php

namespace Tests\Support;

use App\Services\LinkPreview\HostResolver;

/**
 * Test-only DNS: host name → IP addresses. Unknown hosts don't resolve.
 */
class FakeHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $hosts
     */
    public function __construct(private array $hosts) {}

    public function resolve(string $host): array
    {
        return $this->hosts[$host] ?? [];
    }
}
