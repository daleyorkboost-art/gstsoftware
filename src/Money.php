<?php
declare(strict_types=1);
namespace App;
/** All accounting arithmetic uses BCMath strings. Half-up rounding, never binary floats. */
final class Money
{
    public static function number(
        mixed $v,
        string $name,
        int $scale = 2,
        string $max = "999999999.99",
    ): string {
        if (!is_string($v) && !is_int($v)) {
            throw new HttpError(422, "$name must be a decimal string.");
        }
        $v = (string) $v;
        if (
            !preg_match("/^\d{1,12}(?:\.\d{1," . $scale . '})?$/D', $v) ||
            bccomp($v, $max, $scale) > 0
        ) {
            throw new HttpError(422, "Invalid $name.");
        }
        return bcadd($v, "0", $scale);
    }
    public static function round(string $v, int $scale = 2): string
    {
        return bcadd(
            $v,
            bccomp($v, "0", 8) < 0
                ? "-0." . str_repeat("0", $scale) . "5"
                : "0." . str_repeat("0", $scale) . "5",
            $scale,
        );
    }
    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, 2);
    }
}
