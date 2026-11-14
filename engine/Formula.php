<?php
declare(strict_types=1);

/**
 * A calculator for the sums staff type into the game (a crime's success chance, for one).
 *
 * v2 handed these to PHP's eval(), so a formula box was a way to run any code on the server. This works the sum
 * out itself and understands only arithmetic: numbers, + - * / %, ** (power), brackets, named values such as LEVEL,
 * and a short list of functions. Anything else is refused.
 *
 *   formula_value('((WILL*0.8)/2.5)+(LEVEL/4)', ['WILL' => 100, 'LEVEL' => 8])   // 34.0
 *   formula_check('system("ls")')                                                // a message saying why not
 */
const MCC_FORMULA_FUNCTIONS = ['min' => [1, 9], 'max' => [1, 9], 'floor' => [1, 1], 'ceil' => [1, 1], 'round' => [1, 2], 'abs' => [1, 1], 'sqrt' => [1, 1], 'pow' => [2, 2], 'rand' => [2, 2]];

final class FormulaError extends RuntimeException
{
}

/** Work a formula out. $values gives the named values (names are matched whole and in any case). Throws FormulaError. */
function formula_value(string $formula, array $values = []): float
{
    $values = array_change_key_case($values, CASE_UPPER);
    // split into numbers, names, and symbols
    if (strlen($formula) > 2000) {
        throw new FormulaError('The formula is too long.');
    }
    if (preg_match_all('/\s*(\d+\.?\d*(?:[eE][+-]?\d+)?|\.\d+|[A-Za-z_][A-Za-z0-9_]*|\*\*|[-+*\/%(),])/A', $formula, $m, PREG_OFFSET_CAPTURE) === false) {
        throw new FormulaError('The formula could not be read.');
    }
    $tokens = [];
    $end = 0;
    foreach ($m[0] as $i => [$whole, $at]) {
        if ($at !== $end) {
            break;
        }
        $tokens[] = $m[1][$i][0];
        $end = $at + strlen($whole);
    }
    if (trim(substr($formula, $end)) !== '') {
        throw new FormulaError('The formula has something in it that is not arithmetic: "' . substr(trim(substr($formula, $end)), 0, 20) . '".');
    }
    if (!$tokens) {
        throw new FormulaError('The formula is empty.');
    }
    $pos = 0;
    $depth = 0;
    $peek = function () use (&$tokens, &$pos): ?string {
        return $tokens[$pos] ?? null;
    };
    $expr = null;
    $unary = null;
    $primary = function () use (&$tokens, &$pos, &$expr, &$depth, $peek, $values): float {
        $t = $tokens[$pos++] ?? null;
        if ($t === null) {
            throw new FormulaError('The formula stops part-way through.');
        }
        if (is_numeric($t)) {
            return (float)$t;
        }
        if ($t === '(') {
            if (++$depth > 50) {
                throw new FormulaError('The formula has too many brackets inside each other.');
            }
            $v = $expr();
            $depth--;
            if (($tokens[$pos++] ?? null) !== ')') {
                throw new FormulaError('The formula is missing a closing bracket.');
            }
            return $v;
        }
        if (preg_match('/^[A-Za-z_]/', $t)) {
            if ($peek() === '(') {
                $fn = strtolower($t);
                if (!isset(MCC_FORMULA_FUNCTIONS[$fn])) {
                    throw new FormulaError("The formula uses \"{$t}()\", which is not allowed. Allowed: " . implode(', ', array_keys(MCC_FORMULA_FUNCTIONS)) . '.');
                }
                $pos++;
                $args = [];
                if ($peek() !== ')') {
                    do {
                        $args[] = $expr();
                    } while ($peek() === ',' && ++$pos);
                }
                if (($tokens[$pos++] ?? null) !== ')') {
                    throw new FormulaError('The formula is missing a closing bracket.');
                }
                [$least, $most] = MCC_FORMULA_FUNCTIONS[$fn];
                if (count($args) < $least || count($args) > $most) {
                    throw new FormulaError("\"{$fn}()\" was given the wrong number of values.");
                }
                return match ($fn) {
                    'min' => (float)min($args), 'max' => (float)max($args), 'floor' => floor($args[0]), 'ceil' => ceil($args[0]),
                    'round' => round($args[0], (int)($args[1] ?? 0)), 'abs' => abs($args[0]),
                    'sqrt' => $args[0] < 0 ? 0.0 : sqrt($args[0]), 'pow' => (float)($args[0] ** $args[1]),
                    'rand' => (float)mt_rand((int)min($args), (int)max($args)),
                };
            }
            $name = strtoupper($t);
            if (!array_key_exists($name, $values)) {
                throw new FormulaError("The formula uses \"{$t}\", which is not a value it can use" . ($values ? ' (it can use ' . implode(', ', array_keys($values)) . ')' : '') . '.');
            }
            return (float)$values[$name];
        }
        throw new FormulaError("The formula has \"{$t}\" where a number was expected.");
    };
    $power = function () use (&$pos, &$unary, $primary, $peek): float {
        $base = $primary();
        if ($peek() === '**') {
            $pos++;
            return (float)($base ** $unary());       // right to left: 2 ** 3 ** 2 is 2 ** 9
        }
        return $base;
    };
    $unary = function () use (&$pos, &$unary, $power, $peek): float {
        if ($peek() === '-') {
            $pos++;
            return -$unary();
        }
        if ($peek() === '+') {
            $pos++;
            return $unary();
        }
        return $power();
    };
    $term = function () use (&$pos, $unary, $peek): float {
        $v = $unary();
        while (in_array($peek(), ['*', '/', '%'], true)) {
            $op = $peek();
            $pos++;
            $r = $unary();
            if ($op === '*') {
                $v *= $r;
            } elseif ($r == 0) {
                $v = 0.0;                              // a sum that divides by nothing counts as nothing, not a crash
            } else {
                $v = $op === '/' ? $v / $r : fmod($v, $r);
            }
        }
        return $v;
    };
    $expr = function () use (&$pos, $term, $peek): float {
        $v = $term();
        while (in_array($peek(), ['+', '-'], true)) {
            $op = $peek();
            $pos++;
            $v = $op === '+' ? $v + $term() : $v - $term();
        }
        return $v;
    };
    $v = $expr();
    if ($pos < count($tokens)) {
        throw new FormulaError("The formula has \"{$tokens[$pos]}\" where it should have ended or continued with + - * /.");
    }
    return is_finite($v) ? $v : 0.0;
}

/** Why a formula cannot be used, or null if it can. $names lists the named values it is allowed. */
function formula_check(string $formula, array $names = []): ?string
{
    try {
        formula_value($formula, array_fill_keys($names, 1));
        return null;
    } catch (FormulaError $e) {
        return $e->getMessage();
    }
}
