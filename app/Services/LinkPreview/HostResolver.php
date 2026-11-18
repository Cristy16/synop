<?php

namespace App\Services\LinkPreview;

/**
 * Looks up a host name's IP addresses. An interface so tests can fake DNS.
 */
interface HostResolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses; empty when the name doesn't resolve
     */
    public function resolve(string $host): array;
}
