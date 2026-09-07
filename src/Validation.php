<?php
declare(strict_types=1);
namespace App;
final class Validation
{
    public static function text(
        mixed $v,
        string $name,
        int $max = 255,
        bool $required = false,
    ): string {
        if (!is_scalar($v) && $v !== null) {
            throw new HttpError(422, "Invalid $name.");
        }
        $v = trim((string) ($v ?? ""));
        if (($required && $v === "") || mb_strlen($v) > $max) {
            throw new HttpError(422, "Check $name (maximum $max characters).");
        }
        return $v;
    }
    public static function date(mixed $v): string
    {
        $v = self::text($v, "date", 10, true);
        $d = \DateTimeImmutable::createFromFormat("!Y-m-d", $v);
        if (!$d || $d->format("Y-m-d") !== $v) {
            throw new HttpError(422, "Enter a valid date.");
        }
        return $v;
    }
    public static function range(array $v): array
    {
        $from = self::date($v["from"] ?? date("Y-m-01"));
        $to = self::date($v["to"] ?? date("Y-m-d"));
        if ($from > $to) {
            throw new HttpError(422, "From date must precede To date.");
        }
        return [$from, $to];
    }
    public static function gstin(mixed $v): string
    {
        $v = strtoupper(self::text($v, "GSTIN", 15));
        if (
            $v !== "" &&
            !preg_match(
                '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/D',
                $v,
            )
        ) {
            throw new HttpError(
                422,
                "GSTIN must have the expected 15-character format. This is not registration verification.",
            );
        }
        return $v;
    }
    public static function state(mixed $v): string
    {
        $v = self::text($v, "state code", 2, true);
        if (!preg_match('/^(0[1-9]|[12][0-9]|3[0-8]|97)$/D', $v)) {
            throw new HttpError(422, "Select a valid state code.");
        }
        return $v;
    }
    public static function choice(
        mixed $v,
        array $allowed,
        string $name,
    ): string {
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            throw new HttpError(422, "Invalid $name.");
        }
        return $v;
    }
}
