<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use OpenTelemetry\SemConv\Incubating\Attributes\DbIncubatingAttributes;

/**
 * Maps a DBAL driver name onto a `db.system.name` value.
 *
 * The stable `DbAttributes` class only carries the four systems whose values have
 * stabilised (`mysql`, `mariadb`, `postgresql`, `microsoft.sql_server`); `sqlite`,
 * `oracle.db`, `ibm.db2` and the `other_sql` fallback exist only in the incubating
 * class, which is where they are taken from. The attribute *key* is stable in both
 * cases — only some of its values are not.
 *
 * MySQL and MariaDB are indistinguishable from connection parameters: telling them
 * apart needs the server version, which means a round trip to the database, and
 * telemetry does not get to add queries. Both report `mysql`.
 */
final readonly class DatabaseSystem
{
    /** @var array<string, non-empty-string> */
    private const array SYSTEMS = [
        'pdo_mysql' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_MYSQL,
        'mysqli' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_MYSQL,
        'pdo_pgsql' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_POSTGRESQL,
        'pgsql' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_POSTGRESQL,
        'pdo_sqlite' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_SQLITE,
        'sqlite3' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_SQLITE,
        'pdo_sqlsrv' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_MICROSOFT_SQL_SERVER,
        'sqlsrv' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_MICROSOFT_SQL_SERVER,
        'pdo_oci' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_ORACLE_DB,
        'oci8' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_ORACLE_DB,
        'ibm_db2' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_IBM_DB2,
    ];

    /** @return non-empty-string */
    public static function of(mixed $driver): string
    {
        if (!\is_string($driver)) {
            return DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_OTHER_SQL;
        }

        return self::SYSTEMS[$driver] ?? DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_OTHER_SQL;
    }
}
