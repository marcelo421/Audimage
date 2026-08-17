<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Verifies a Google Identity Services ID token (JWT) locally against
 * Google's published JWKS public keys, instead of calling the
 * https://oauth2.googleapis.com/tokeninfo debug endpoint.
 *
 * Google explicitly documents tokeninfo as rate-limited and intended for
 * debugging only — production code should validate the RS256 signature
 * against the JWKS keys. See:
 * https://developers.google.com/identity/openid-connect/openid-connect#validatinganidtoken
 *
 * This is a minimal, dependency-free implementation. For anything beyond
 * a small project, prefer firebase/php-jwt + google/apiclient, which
 * handle JWKS caching, key rotation and edge cases more robustly.
 */
class GoogleTokenVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];
    private const CACHE_FILE = __DIR__ . '/../../storage/.google_jwks_cache.json';
    private const CACHE_TTL = 3600;

    public function __construct(private string $clientId)
    {
    }

    /**
     * @return array{email:string,email_verified:bool,name:string} Decoded claims
     * @throws RuntimeException on any validation failure
     */
    public function verify(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new RuntimeException('Token do Google malformado.');
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;
        $header = json_decode(self::base64UrlDecode($headerB64), true);
        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        $signature = self::base64UrlDecode($sigB64);

        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('Token do Google malformado.');
        }

        if (($header['alg'] ?? '') !== 'RS256') {
            throw new RuntimeException('Algoritmo de assinatura não suportado.');
        }

        $kid = $header['kid'] ?? null;
        if (!$kid) {
            throw new RuntimeException('Token do Google sem key id.');
        }

        $publicKey = $this->getPublicKey((string)$kid);
        $signedData = $headerB64 . '.' . $payloadB64;

        $verified = openssl_verify($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new RuntimeException('Assinatura do token do Google inválida.');
        }

        if (!in_array($payload['iss'] ?? '', self::ISSUERS, true)) {
            throw new RuntimeException('Emissor do token inválido.');
        }

        if (($payload['aud'] ?? '') !== $this->clientId) {
            throw new RuntimeException('Token do Google inválido para esta aplicação.');
        }

        if (($payload['exp'] ?? 0) < time()) {
            throw new RuntimeException('Token do Google expirado.');
        }

        if (($payload['email_verified'] ?? false) !== true && ($payload['email_verified'] ?? '') !== 'true') {
            throw new RuntimeException('Email do Google não verificado.');
        }

        $email = trim((string)($payload['email'] ?? ''));
        if ($email === '') {
            throw new RuntimeException('Email do Google não encontrado no token.');
        }

        return [
            'email' => $email,
            'email_verified' => true,
            'name' => (string)($payload['name'] ?? ''),
        ];
    }

    private function getPublicKey(string $kid): \OpenSSLAsymmetricKey|string
    {
        $jwks = $this->loadJwks();
        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['kid'] ?? '') === $kid) {
                return self::jwkToPem($key);
            }
        }
        throw new RuntimeException('Chave pública do Google não encontrada (kid desconhecido).');
    }

    private function loadJwks(): array
    {
        if (is_file(self::CACHE_FILE) && (time() - filemtime(self::CACHE_FILE)) < self::CACHE_TTL) {
            $cached = json_decode((string)file_get_contents(self::CACHE_FILE), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $raw = $this->fetch(self::JWKS_URL);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Falha ao obter chaves públicas do Google.');
        }

        $dir = dirname(self::CACHE_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        @file_put_contents(self::CACHE_FILE, $raw, LOCK_EX);

        return $decoded;
    }

    private function fetch(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $result = curl_exec($ch);
            $ok = $result !== false;
            curl_close($ch);
            if ($ok) {
                return (string)$result;
            }
        }

        $result = @file_get_contents($url);
        if ($result === false) {
            throw new RuntimeException('Falha de rede ao buscar chaves do Google.');
        }
        return $result;
    }

    private static function jwkToPem(array $jwk): \OpenSSLAsymmetricKey
    {
        $n = self::base64UrlDecode($jwk['n']);
        $e = self::base64UrlDecode($jwk['e']);

        $modulus = self::asn1Integer($n);
        $exponent = self::asn1Integer($e);
        $rsaPublicKey = self::asn1Sequence($modulus . $exponent);

        $rsaOid = pack('H*', '300d06092a864886f70d0101010500');
        $bitString = self::asn1BitString($rsaPublicKey);
        $spki = self::asn1Sequence($rsaOid . $bitString);

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new RuntimeException('Falha ao montar chave pública do Google.');
        }
        return $key;
    }

    private static function asn1Integer(string $bytes): string
    {
        if (ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::asn1Length(strlen($bytes)) . $bytes;
    }

    private static function asn1BitString(string $bytes): string
    {
        $bytes = "\x00" . $bytes;
        return "\x03" . self::asn1Length(strlen($bytes)) . $bytes;
    }

    private static function asn1Sequence(string $bytes): string
    {
        return "\x30" . self::asn1Length(strlen($bytes)) . $bytes;
    }

    private static function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = str_pad($data, strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');
        return (string)base64_decode(strtr($padded, '-_', '+/'));
    }
}
