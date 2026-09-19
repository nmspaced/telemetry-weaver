<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Metrics\View\SelectionCriteriaInterface;
use OpenTelemetry\SDK\Metrics\View\ViewTemplate;

/**
 * One view: which instruments it applies to, and what it does to them.
 *
 * The SDK has no type for the pair — `CriteriaViewRegistry::register()` takes the two halves
 * as separate arguments — and a configuration key can only name one service. So the pair is
 * this, and nothing more: both halves are the SDK's own types, passed through untouched.
 *
 * A view is the specification's answer to the two questions `duration_buckets` does not
 * cover: changing the aggregation of an instrument the bundle did not create, and dropping an
 * attribute whose cardinality is unbounded.
 *
 * ```php
 * // config/services.php
 * $services
 *     ->set('app.telemetry.drop_route_label', MetricView::class)
 *     ->args([
 *         new InstrumentNameCriteria('http.server.request.duration'),
 *         ViewTemplate::create()->withAttributeKeys(['http.response.status_code']),
 *     ]);
 * ```
 *
 * @api
 */
final readonly class MetricView
{
    public function __construct(
        public SelectionCriteriaInterface $criteria,
        public ViewTemplate $template,
    ) {}
}
