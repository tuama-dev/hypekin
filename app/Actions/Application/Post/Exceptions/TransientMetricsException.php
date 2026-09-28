<?php

namespace App\Actions\Application\Post\Exceptions;

use RuntimeException;

/**
 * A metrics fetch failed in a way that is likely temporary (network blip,
 * platform 5xx, rate limit). The caller records nothing so the scheduler
 * retries the target on a later run, instead of poisoning it permanently.
 */
class TransientMetricsException extends RuntimeException
{
    //
}
