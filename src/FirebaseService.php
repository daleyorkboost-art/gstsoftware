<?php
declare(strict_types=1);
namespace App;
final class FirebaseService
{
    public function verify(string $token): array
    {
        if (strlen($token) > 10000 || count(explode(".", $token)) !== 3) {
            throw new HttpError(401, "Please sign in again.");
        }
        [$h, $p, $sig] = explode(".", $token);
        $header = json_decode($this->decode($h), true);
        $claims = json_decode($this->decode($p), true);
        $project = Config::required("FIREBASE_PROJECT_ID");
        $now = time();
        if (
            !is_array($header) ||
            !is_array($claims) ||
            ($header["alg"] ?? "") !== "RS256" ||
            !is_string($header["kid"] ?? null) ||
            ($claims["aud"] ?? "") !== $project ||
            ($claims["iss"] ?? "") !==
                "https://securetoken.google.com/" . $project ||
            !is_int($claims["exp"] ?? null) ||
            $claims["exp"] <= $now ||
            ($claims["iat"] ?? PHP_INT_MAX) > $now + 30 ||
            ($claims["auth_time"] ?? PHP_INT_MAX) > $now + 30 ||
            !is_string($claims["sub"] ?? null) ||
            strlen($claims["sub"]) < 1 ||
            strlen($claims["sub"]) > 128
        ) {
            throw new HttpError(401, "Invalid or expired sign-in.");
        }
        $file = ROOT . "/storage/firebase-certificates.json";
        $keys = [];
        if (is_file($file) && filemtime($file) > time() - 1800) {
            $keys = json_decode(file_get_contents($file), true) ?? [];
        }
        if (!isset($keys[$header["kid"]])) {
            $keys = HttpClient::request(
                "https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com",
            );
            file_put_contents($file, json_encode($keys), LOCK_EX);
        }
        if (
            !isset($keys[$header["kid"]]) ||
            openssl_verify(
                $h . "." . $p,
                $this->decode($sig),
                $keys[$header["kid"]],
                OPENSSL_ALGO_SHA256,
            ) !== 1
        ) {
            throw new HttpError(401, "Invalid sign-in signature.");
        }
        // Online lookup also rejects deleted/disabled accounts and tokens invalidated by account changes.
        $lookup = HttpClient::request(
            "https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=" .
                rawurlencode(Config::required("FIREBASE_API_KEY")),
            ["idToken" => $token],
        );
        $account = $lookup["users"][0] ?? [];
        if (
            ($account["localId"] ?? "") !== $claims["sub"] ||
            ($account["disabled"] ?? false) ||
            (int) ($account["validSince"] ?? 0) > (int) $claims["auth_time"]
        ) {
            throw new HttpError(401, "This account is unavailable.");
        }
        return [
            "uid" => $claims["sub"],
            "email" => strtolower($account["email"] ?? ""),
            "verified" => $account["emailVerified"] ?? false,
            "auth_time" => (int) $claims["auth_time"],
        ];
    }
    private function decode(string $s): string
    {
        $v = base64_decode(strtr($s, "-_", "+/"), true);
        if ($v === false) {
            throw new HttpError(401, "Invalid token.");
        }
        return $v;
    }
    public function admin(string $operation, array $payload): array
    {
        $path = Config::required("FIREBASE_SERVICE_ACCOUNT_PATH");
        if (!is_file($path)) {
            throw new HttpError(
                503,
                "Firebase service account is not configured.",
            );
        }
        $sa = json_decode(
            file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $enc = fn($v) => rtrim(
            strtr(
                base64_encode(json_encode($v, JSON_THROW_ON_ERROR)),
                "+/",
                "-_",
            ),
            "=",
        );
        $unsigned =
            $enc(["alg" => "RS256", "typ" => "JWT"]) .
            "." .
            $enc([
                "iss" => $sa["client_email"],
                "scope" => "https://www.googleapis.com/auth/identitytoolkit",
                "aud" => "https://oauth2.googleapis.com/token",
                "iat" => time(),
                "exp" => time() + 3500,
            ]);
        if (
            !openssl_sign(
                $unsigned,
                $signature,
                $sa["private_key"],
                OPENSSL_ALGO_SHA256,
            )
        ) {
            throw new HttpError(503, "Service account signing failed.");
        }
        $jwt =
            $unsigned .
            "." .
            rtrim(strtr(base64_encode($signature), "+/", "-_"), "=");
        $auth = HttpClient::request(
            "https://oauth2.googleapis.com/token",
            [
                "grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer",
                "assertion" => $jwt,
            ],
            [],
            true,
        );
        return HttpClient::request(
            "https://identitytoolkit.googleapis.com/v1/projects/" .
                rawurlencode(Config::required("FIREBASE_PROJECT_ID")) .
                "/accounts:" .
                $operation,
            $payload,
            ["Authorization: Bearer " . $auth["access_token"]],
        );
    }
    public function sendPasswordReset(string $email): void
    {
        HttpClient::request(
            "https://identitytoolkit.googleapis.com/v1/accounts:sendOobCode?key=" .
                rawurlencode(Config::required("FIREBASE_API_KEY")),
            ["requestType" => "PASSWORD_RESET", "email" => $email],
        );
    }
}
