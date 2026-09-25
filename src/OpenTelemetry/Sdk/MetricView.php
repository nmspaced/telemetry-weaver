<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Metrics\View\SelectionCriteriaInterface;
use OpenTelemetry\SDK\Metrics\View\ViewTemplate;

/**
 * One metric view: the instrument selector and what it changes, as SDK types.
 *
 * ```php
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
