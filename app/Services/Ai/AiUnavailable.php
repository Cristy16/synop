<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The AI couldn't be used: no key, a timeout, an error, or a reply that doesn't
 * make sense. Callers always fall back (ARCHITECTURE §7).
 */
class AiUnavailable extends RuntimeException {}
