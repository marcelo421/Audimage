<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Validates Google Sign-In ID tokens locally using Google's published JWKS
 * (RS256), instead of relying on the debug-only `tokeninfo` endpoint.
 */
class GoogleTokenVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public function __construct(private string $expectedAudience)
    {
    }

    /**
     * @return array<string, mixed> the decoded, verified payload
     * @throws RuntimeException on any validation failure
     */
    public function verify(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new RuntimeException('Formato de token inválido.');
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        $signature = $this->base64UrlDecode($sigB64);

        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('Token malformado.');
        }

        if (($header['alg'] ?? '') !== 'RS256') {
            throw new RuntimeException('Algoritmo de assinatura não suportado.');
        }

        $kid = $header['kid'] ?? null;
        if (!$kid) {
            throw new RuntimeException('Token sem identificador de chave.');
        }

        $publicKeyPem = $this->getPublicKeyForKid((string)$kid);
        $signedInput = $headerB64 . '.' . $payloadB64;

        $pubKey = openssl_pkey_get_public($publicKeyPem);
        if ($pubKey === false) {
            throw new RuntimeException('Falha ao carregar chave pública do Google.');
        }

        $verified = openssl_verify($signedInput, $signature, $pubKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new RuntimeException('Assinatura do token inválida.');
        }

        $this->assertClaims($payload);

        return $payload;
    }

    private function assertClaims(array $payload): void
    {
        $now = time();

        if (!in_array($payload['iss'] ?? '', self::ISSUERS, true)) {
            throw new RuntimeException('Emissor do token inválido.');
        }

        if (($payload['aud'] ?? '') !== $this->expectedAudience) {
            throw new RuntimeException('Token não emitido para esta aplicação.');
        }

        if (!isset($payload['exp']) || $now >= (int)$payload['exp']) {
            throw new RuntimeException('Token expirado.');
        }

        if (isset($payload['iat']) && (int)$payload['iat'] > $now + 60) {
            throw new RuntimeException('Token emitido no futuro.');
        }

        $emailVerified = $payload['email_verified'] ?? false;
        if ($emailVerified !== true && $emailVerified !== 'true') {
            throw new RuntimeException('Email do Google não verificado.');
        }

        if (empty($payload['email'])) {
            throw new RuntimeException('Email do Google não encontrado.');
        }
    }

    private function getPublicKeyForKid(string $kid): string
    {
        $jwks = $this->fetchJwks();

        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (($jwk['kid'] ?? '') === $kid) {
                return $this->jwkToPem($jwk);
            }
        }

        throw new RuntimeException('Chave de assinatura do Google não encontrada (kid desconhecido).');
    }

    /**
     * Fetches and lightly caches Google's JWKS for the lifetime of the process/cache file.
     */
    private function fetchJwks(): array
    {
        $cacheFile = sys_get_temp_dir() . '/audimage_google_jwks.json';
        $cacheTtl = 3600;

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['keys'])) {
                return $cached;
            }
        }

        $raw = $this->fetchUrl(self::JWKS_URL);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['keys'])) {
            // Serve stale cache rather than fail completely, if we have one.
            if (is_file($cacheFile)) {
                $stale = json_decode((string)file_get_contents($cacheFile), true);
                if (is_array($stale) && !empty($stale['keys'])) {
                    return $stale;
                }
            }
            throw new RuntimeException('Falha ao obter chaves públicas do Google.');
        }

        @file_put_contents($cacheFile, json_encode($decoded), LOCK_EX);

        return $decoded;
    }

    private function fetchUrl(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $result = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            if ($result === false) {
                throw new RuntimeException('Falha de rede ao contatar o Google: ' . $error);
            }
            return (string)$result;
        }

        $result = @file_get_contents($url);
        if ($result === false) {
            throw new RuntimeException('Falha de rede ao contatar o Google.');
        }
        return $result;
    }

    /**
     * Converts an RSA JWK (n, e) into a PEM-encoded public key using DER/ASN.1 encoding,
     * without requiring any third-party JWT/crypto library.
     */
    private function jwkToPem(array $jwk): string
    {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            throw new RuntimeException('Formato de chave JWK inesperado.');
        }

        $modulus = $this->base64UrlDecode($jwk['n']);
        $exponent = $this->base64UrlDecode($jwk['e']);

        $modulusEncoded = $this->derEncodeInteger($modulus);
        $exponentEncoded = $this->derEncodeInteger($exponent);

        $rsaPublicKey = $this->derEncodeSequence($modulusEncoded . $exponentEncoded);

        // RSA algorithm identifier: SEQUENCE { OID rsaEncryption, NULL }
        $algorithmIdentifier = $this->derEncodeSequence(
            hex2bin('06092a864886f70d0101010500') // OID 1.2.840.113549.1.1.1 + NULL
        );

        $publicKeyBitString = "\x00" . $rsaPublicKey; // prepend unused-bits byte
        $bitString = $this->derEncode(0x03, $publicKeyBitString);

        $spki = $this->derEncodeSequence($algorithmIdentifier . $bitString);

        $base64 = base64_encode($spki);
        $chunks = chunk_split($base64, 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n{$chunks}-----END PUBLIC KEY-----\n";
    }

    private function derEncodeInteger(string $bin): string
    {
        // Strip leading zero bytes, but keep a leading 0x00 if the high bit is set
        // (so it isn't interpreted as a negative number).
        $bin = ltrim($bin, "\x00");
        if ($bin === '') {
            $bin = "\x00";
        }
        if ((ord($bin[0]) & 0x80) !== 0) {
            $bin = "\x00" . $bin;
        }
        return $this->derEncode(0x02, $bin);
    }

    private function derEncodeSequence(string $bin): string
    {
        return $this->derEncode(0x30, $bin);
    }

    private function derEncode(int $tag, string $bin): string
    {
        $length = strlen($bin);
        if ($length < 128) {
            $lengthBytes = chr($length);
        } else {
            $temp = ltrim(pack('N', $length), "\x00");
            $lengthBytes = chr(0x80 | strlen($temp)) . $temp;
        }
        return chr($tag) . $lengthBytes . $bin;
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'));
        if ($decoded === false) {
            throw new RuntimeException('Falha ao decodificar token.');
        }
        return $decoded;
    }
}
