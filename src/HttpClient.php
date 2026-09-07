<?php
declare(strict_types=1);
namespace App;
final class HttpClient
{
    public static function request(
        string $url,
        ?array $body = null,
        array $headers = [],
        bool $form = false,
    ): array {
        if (!str_starts_with($url, "https://")) {
            throw new HttpError(503, "External service must use HTTPS.");
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => array_merge(
                [
                    "Content-Type: " .
                    ($form
                        ? "application/x-www-form-urlencoded"
                        : "application/json"),
                ],
                $headers,
            ),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                $form
                    ? http_build_query($body)
                    : json_encode($body, JSON_THROW_ON_ERROR),
            );
        }
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new HttpError(
                502,
                "External service rejected the request or is unavailable. Check configuration and account status.",
            );
        }
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
