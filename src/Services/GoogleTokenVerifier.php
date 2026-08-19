<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Validates Google-issued ID tokens (JWTs) locally against Google's published
 * JWKS, using RS256 signature verification. This replaces the debug-only
 * `tokeninfo` endpoint, which is not intended for production use, has no SLA,
 * and imposes an aggressive rate limit that becomes a single point of failure
 * for login under load.
 */
class GoogleTokenVerifier
{
    private const JWKS_URI = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ALLOWED_ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];
    private const JWKS_CACHE_TTL = 3600;
    private const CLOCK_SKEW_SECONDS = 60;

    private string $clientId;
    private string $cacheFile;

    public function __construct(string $clientId, ?string $cacheFile = null)
    {
        $this->clientId = $clientId;
        $this->cacheFile = $cacheFile ?? sys_get_temp_dir() . '/audimage_google_jwks_cache.json';
    }

    /**
     * Returns the decoded JWT payload on success, or null if the token fails
     * any structural, signature, or claim check. Callers must treat null as
     * "reject the login" — never fall back to trusting an unverified payload.
     */
    public function verify(string $idToken): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        $signature = $this->base64UrlDecode($signatureB64);

        if (!is_array($header) || !is_array($payload) || $signature === '') {
            return null;
        }

        // Reject "alg: none" and any algorithm confusion attempts outright.
        if (($header['alg'] ?? null) !== 'RS256') {
            return null;
        }

        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            return null;
        }

        $publicKeyPem = $this->resolvePublicKey($kid);
        if ($publicKeyPem === null) {
            return null;
        }

        $signingInput = $headerB64 . '.' . $payloadB64;
        $verified = openssl_verify($signingInput, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return null;
        }

        if (!$this->claimsAreValid($payload)) {
            return null;
        }

        return $payload;
    }

    private function claimsAreValid(array $payload): bool
    {
        if (!in_array($payload['iss'] ?? null, self::ALLOWED_ISSUERS, true)) {
            return false;
        }

        if (($payload['aud'] ?? null) !== $this->clientId) {
            return false;
        }

        $now = time();

        if (!isset($payload['exp']) || $now >= ((int)$payload['exp'] + self::CLOCK_SKEW_SECONDS)) {
            return false;
        }

        if (isset($payload['iat']) && (int)$payload['iat'] > ($now + self::CLOCK_SKEW_SECONDS)) {
            return false;
        }

        return true;
    }

    private function resolvePublicKey(string $kid): ?string
    {
        $jwk = $this->findKey($kid, $this->loadJwks(false));
        if ($jwk === null) {
            // Google rotates signing keys; force one fresh fetch before giving up,
            // in case our cache is simply stale.
            $jwk = $this->findKey($kid, $this->loadJwks(true));
        }

        return $jwk !== null ? $this->jwkToPem($jwk) : null;
    }

    private function findKey(string $kid, array $jwks): ?array
    {
        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }
        return null;
    }

    private function loadJwks(bool $forceRefresh): array
    {
        if (!$forceRefresh && is_file($this->cacheFile)) {
            $raw = @file_get_contents($this->cacheFile);
            $decoded = $raw !== false ? json_decode($raw, true) : null;
            if (
                is_array($decoded)
                && isset($decoded['fetched_at'], $decoded['jwks'])
                && (time() - (int)$decoded['fetched_at']) < self::JWKS_CACHE_TTL
            ) {
                return $decoded['jwks'];
            }
        }

        $fetched = $this->fetchJwks();
        return $fetched ?? [];
    }

    private function fetchJwks(): ?array
    {
        $raw = $this->httpGet(self::JWKS_URI);
        if ($raw === null) {
            return null;
        }

        $jwks = json_decode($raw, true);
        if (!is_array($jwks) || !isset($jwks['keys'])) {
            return null;
        }

        @file_put_contents(
            $this->cacheFile,
            json_encode(['fetched_at' => time(), 'jwks' => $jwks], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        return $jwks;
    }

    private function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $result = curl_exec($ch);
            curl_close($ch);
            return $result !== false ? $result : null;
        }

        $context = stream_context_create(['http' => ['timeout' => 10]]);
        $result = @file_get_contents($url, false, $context);
        return $result !== false ? $result : null;
    }

    /**
     * Rebuilds a DER-encoded SubjectPublicKeyInfo (PEM) RSA public key from a
     * JWK's modulus (n) and exponent (e), so openssl_verify() can use it.
     */
    private function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || !isset($jwk['n'], $jwk['e'])) {
            return null;
        }

        $modulus = $this->base64UrlDecode($jwk['n']);
        $exponent = $this->base64UrlDecode($jwk['e']);

        if ($modulus === '' || $exponent === '') {
            return null;
        }

        // DER INTEGERs are two's-complement/signed: if the high bit of the
        // leading byte is set, prefix a 0x00 so it isn't read as negative.
        if ((ord($modulus[0]) & 0x80) !== 0) {
            $modulus = "\x00" . $modulus;
        }
        if ((ord($exponent[0]) & 0x80) !== 0) {
            $exponent = "\x00" . $exponent;
        }

        $rsaPublicKey = $this->derInteger($modulus) . $this->derInteger($exponent);
        $rsaPublicKeySequence = $this->derSequence($rsaPublicKey);

        // BIT STRING wrapping the RSAPublicKey SEQUENCE (leading 0x00 = no unused bits)
        $bitStringContent = "\x00" . $rsaPublicKeySequence;
        $bitString = "\x03" . $this->derLength(strlen($bitStringContent)) . $bitStringContent;

        // AlgorithmIdentifier for rsaEncryption (1.2.840.113549.1.1.1) + NULL params
        $algorithmIdentifier = hex2bin('300d06092a864886f70d0101010500');
        if ($algorithmIdentifier === false) {
            return null;
        }

        $spki = $this->derSequence($algorithmIdentifier . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function derInteger(string $bytes): string
    {
        return "\x02" . $this->derLength(strlen($bytes)) . $bytes;
    }

    private function derSequence(string $bytes): string
    {
        return "\x30" . $this->derLength(strlen($bytes)) . $bytes;
    }

    private function derLength(int $length): string
    {
        if ($length <= 0x7f) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);
        return $decoded !== false ? $decoded : '';
    }
}
