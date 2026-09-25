<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * A duration instrument. Create it once with `Metrics::duration()` and pass it to
 * `Operation::duration()`; the operation owns the clock.
 *
 * @api
 */
interface Duration {}
