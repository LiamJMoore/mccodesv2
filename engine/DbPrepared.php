<?php
declare(strict_types=1);

/**
 * Queries with bound parameters, for the game's database class.
 *
 * The v2 way was to build SQL as a string and remember to escape or cast every value by hand:
 *
 *     $db->query("SELECT * FROM `users` WHERE `userid` = {$id} AND `username` = '" . $db->escape($name) . "'");
 *
 * One forgotten escape is an SQL injection. The v3 way sends the statement and the values separately, so a value
 * can never be read as SQL, whatever it holds:
 *
 *     $user = $db->row('SELECT * FROM `users` WHERE `userid` = ? AND `username` = ?', [$id, $name]);
 *
 * Every method records the statement in $db->queries and counts it, as query() does, and stops the page with the
 * game's own error screen if the database refuses it.
 */
trait DbPrepared
{
    /** Run a statement with its values bound. Returns the result for a SELECT, true for anything else. */
    public function run(string $sql, array $params = []): mysqli_result|bool
    {
        $this->last_query = $sql;
        $this->queries[]  = $sql;
        $this->num_queries++;
        try {
            $stmt = mysqli_prepare($this->connection_id, $sql);
            if ($stmt === false) {
                $this->prepared_failed($sql);
            }
            if ($params) {
                $types = '';
                $bound = [];
                foreach (array_values($params) as $v) {
                    if (is_bool($v)) {
                        $v = (int)$v;
                    }
                    $types  .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
                    $bound[] = $v;
                }
                mysqli_stmt_bind_param($stmt, $types, ...$bound);
            }
            if (!mysqli_stmt_execute($stmt)) {
                $this->prepared_failed($sql);
            }
            $this->prepared_affected = mysqli_stmt_affected_rows($stmt);
            $this->prepared_insert_id = mysqli_stmt_insert_id($stmt);
            $result = mysqli_stmt_get_result($stmt);
            mysqli_stmt_close($stmt);
        } catch (mysqli_sql_exception $e) {
            $this->prepared_failed($sql, $e->getCode() . ': ' . $e->getMessage());
        }
        $this->result = $result === false ? true : $result;
        return $this->result;
    }

    /** The first row as an array, or null when there is none. */
    public function row(string $sql, array $params = []): ?array
    {
        $r = $this->run($sql, $params);
        if (!$r instanceof mysqli_result) {
            return null;
        }
        $row = mysqli_fetch_assoc($r);
        mysqli_free_result($r);
        return $row ?: null;
    }

    /** The first column of the first row, or $default when there is no row. */
    public function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $r = $this->run($sql, $params);
        if (!$r instanceof mysqli_result) {
            return $default;
        }
        $row = mysqli_fetch_row($r);
        mysqli_free_result($r);
        return $row ? $row[0] : $default;
    }

    /** Every row, as a list of arrays. */
    public function all(string $sql, array $params = []): array
    {
        $r = $this->run($sql, $params);
        if (!$r instanceof mysqli_result) {
            return [];
        }
        $rows = mysqli_fetch_all($r, MYSQLI_ASSOC);
        mysqli_free_result($r);
        return $rows;
    }

    /** Run an INSERT, UPDATE or DELETE and return how many rows it changed. */
    public function exec(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return max(0, (int)$this->prepared_affected);
    }

    /** Insert one row from column => value, and return its new id. Column names are checked, values are bound. */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        foreach (array_merge([$table], $cols) as $name) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$name)) {
                $this->prepared_failed("INSERT INTO {$table}", 'Not a valid table or column name: ' . $name);
            }
        }
        $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $this->run($sql, array_values($data));
        return (int)$this->prepared_insert_id;
    }

    /**
     * Run several statements as one: all of them happen, or none do. Use it wherever two rows must change together
     * (money leaving one player and reaching another). The callback's return value is passed back.
     * One transaction may be started inside another: the inner one becomes a savepoint, so it can be undone
     * by itself without ending the outer one.
     */
    public function transaction(callable $work): mixed
    {
        $depth = $this->tx_depth++;
        $depth === 0 ? mysqli_begin_transaction($this->connection_id) : mysqli_query($this->connection_id, "SAVEPOINT mcc_sp{$depth}");
        try {
            $out = $work($this);
            $depth === 0 ? mysqli_commit($this->connection_id) : mysqli_query($this->connection_id, "RELEASE SAVEPOINT mcc_sp{$depth}");
            return $out;
        } catch (Throwable $e) {
            $depth === 0 ? mysqli_rollback($this->connection_id) : mysqli_query($this->connection_id, "ROLLBACK TO SAVEPOINT mcc_sp{$depth}");
            throw $e;
        } finally {
            $this->tx_depth--;
        }
    }

    /**
     * Try a set of changes that must all succeed. The callback returns true when every step worked; if it returns
     * false, everything it did is undone and attempt() returns false.
     *
     *     $ok = $db->attempt(fn () => listing_claim('itemmarket', $id, $qty) && wallet_send($buyer, $seller, 'money', $price));
     */
    public function attempt(callable $steps): bool
    {
        try {
            return (bool)$this->transaction(function () use ($steps) {
                if (!$steps($this)) {
                    throw new DomainException('a step failed');
                }
                return true;
            });
        } catch (DomainException) {
            return false;
        }
    }

    private int $tx_depth = 0;
    public int|string $prepared_affected = 0;
    public int|string $prepared_insert_id = 0;

    private function prepared_failed(string $sql, ?string $why = null): never
    {
        $why = $why ?? (mysqli_errno($this->connection_id) . ': ' . mysqli_error($this->connection_id));
        if (function_exists('error_critical')) {
            error_critical($why, 'Attempted to execute query: ' . nl2br($sql), debug_backtrace());
        }
        throw new RuntimeException($why);
    }
}
