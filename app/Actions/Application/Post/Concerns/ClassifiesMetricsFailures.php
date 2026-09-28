<?php

namespace App\Actions\Application\Post\Concerns;

use App\Actions\Application\Post\Exceptions\TransientMetricsException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Shared failure classification for the platform metrics fetchers.
 *
 * Transport errors and server-side/noisy responses (429, 5xx) are transient:
 * recording nothing lets the scheduler retry the target on its next run.
 * Everything else (client errors, missing preconditions) is permanent and
 * surfaces as a terminal error snapshot.
 */
trait ClassifiesMetricsFailures
{
    /**
     * A request could not reach the platform (timeout, connection reset, …).
     *
     * @throws TransientMetricsException
     */
    protected function transportMetricsFailure(string $message, Throwable $previous): never
    {
        throw new TransientMetricsException($message, 0, $previous);
    }

    /**
     * The platform answered but the request failed.
     *
     * @throws TransientMetricsException|RuntimeException
     */
    protected function respondMetricsFailure(Response $response): never
    {
        $status = $response->status();

        if ($status === 429 || $status >= 500) {
            throw new TransientMetricsException($status.' returned by the platform; retrying later.');
        }

        throw new RuntimeException($this->metricsErrorMessage($response));
    }

    /**
     * Best-effort extraction of the platform's error message for permanent
     * failures. Implementations override this when the payload shape differs.
     */
    protected function metricsErrorMessage(Response $response): ?string
    {
        $message = $response->json('error.message') ?? $response->json('message');

        return $message !== null
            ? $message
            : 'The platform denied the request ('.$response->status().').';
    }
}
