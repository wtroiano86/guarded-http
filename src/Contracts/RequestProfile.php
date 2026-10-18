<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Contracts;

use Wtroiano\GuardedHttp\Support\RequestSettings;

/**
 * Supplies an application-owned request definition with a policy and limits.
 *
 * Profile classes can receive dependencies through Laravel's container.
 * Profiles select endpoint policies; they do not replace destination checks.
 */
interface RequestProfile
{
    /**
     * Return settings for the current pipeline invocation.
     *
     * RequestPipeline validates numeric limits when constructing the pending
     * request. Destination resolution is deferred until the request is sent.
     */
    public function settings(): RequestSettings;
}
