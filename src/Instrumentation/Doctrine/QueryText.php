<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

/**
 * What `db.query.text` carries. `Sanitized` is the default because the database conventions
 * only allow query text by default once literals are replaced.
 */
enum QueryText: string
{
    /** Literals replaced by `?`, comments removed; no text when that cannot be done safely. */
    case Sanitized = 'sanitized';

    /** The statement as sent, literals included. */
    case Raw = 'raw';

    case Off = 'off';
}
