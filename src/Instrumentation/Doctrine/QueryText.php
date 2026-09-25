<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

/**
 * What `db.query.text` carries.
 *
 * `Sanitized` is the default because it is what the database conventions ask for:
 * query text is recorded by default only when every literal has been replaced. `Raw` is the
 * statement exactly as sent, literals included, for an application that has decided its
 * statements carry nothing it may not export.
 */
enum QueryText: string
{
    /** Literals replaced by `?`, comments removed; nothing when that cannot be done with certainty. */
    case Sanitized = 'sanitized';

    /** The statement as sent, literals and all. */
    case Raw = 'raw';

    case Off = 'off';
}
