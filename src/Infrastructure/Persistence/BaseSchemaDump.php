<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use PDO;
use RuntimeException;

/**
 * Applies the base dump (`database.sql`) so a fresh install is one command.
 *
 * The dump creates the core tables and reference data; everything after it is
 * additive and applied by DatabaseSchema::ensure(). Statements are split here
 * instead of relying on multi-statement `exec()` support, and the database
 * selection statements are skipped because the connection already targets it.
 *
 * Applying is only safe once: the dump's INSERTs carry fixed primary keys, so a
 * second run would collide. Callers check `isInstalled()` first.
 */
final class BaseSchemaDump
{
    public function __construct(private string $path)
    {
    }

    /**
     * Split a SQL script into individual statements.
     *
     * Handles quoted strings, backtick identifiers and `--` line comments, which
     * is all the dump contains. Escaped quotes are honoured so JSON payloads in
     * seed rows cannot terminate a statement early.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $statements = [];
        $current = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote === null && $char === '-' && ($sql[$i + 1] ?? '') === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $current .= ' ';
                continue;
            }

            if ($quote === null && ($char === "'" || $char === '"' || $char === '`')) {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($quote !== null) {
                if ($char === '\\' && $quote !== '`') {
                    $current .= $char . ($sql[$i + 1] ?? '');
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                $current .= $char;
                continue;
            }

            if ($char === ';') {
                $statement = trim($current);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $statement = trim($current);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    /** True when the configured database already has the core tables. */
    public function isInstalled(PDO $database): bool
    {
        try {
            $database->query('SELECT 1 FROM config LIMIT 1');
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * @return int number of statements executed
     */
    public function apply(PDO $database): int
    {
        if (!is_readable($this->path)) {
            throw new RuntimeException('Base schema dump is missing: ' . $this->path);
        }

        $executed = 0;
        foreach (self::statements((string) file_get_contents($this->path)) as $statement) {
            if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $statement)) {
                continue;
            }
            $database->exec($statement);
            $executed++;
        }

        return $executed;
    }
}
