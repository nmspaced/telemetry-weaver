<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Trace\SpanSuppression\SemanticConventionResolver;
use OpenTelemetry\SDK\Trace\SpanSuppression\SemanticConventionSuppressionStrategy\SemanticConventionSuppressionStrategy;
use OpenTelemetry\SDK\Trace\SpanSuppression\SpanSuppressionStrategy;

final readonly class SpanSuppressionStrategyFactory
{
    public static function create(): SpanSuppressionStrategy
    {
        // @mago-expect analysis:experimental-usage — span suppression is experimental upstream and the bundle depends on it deliberately
        $resolvers = ServiceLoader::load(SemanticConventionResolver::class);

        // @mago-expect analysis:experimental-usage — span suppression is experimental upstream and the bundle depends on it deliberately
        return new SemanticConventionSuppressionStrategy($resolvers);
    }
}
