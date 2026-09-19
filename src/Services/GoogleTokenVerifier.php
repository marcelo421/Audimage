<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Valida tokens de login do Google localmente usando as chaves públicas públicas
 * do Google (JWKS) e o algoritmo RS256.
 * Em termos simples: confirma que o token veio mesmo do Google e foi emitido para este app.
 */
class GoogleTokenVerifier
{
    // URL pública do Google que entrega as chaves de assinatura dos tokens.
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    // Emissores válidos do Google. O token precisa vir de um desses endereços.
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public function __construct(
        private string $expectedAudience,
        private ?string $cacheFile = null
    )
    {
    }

    /**
     * Verifica um token do Google inteiro.
     * Faz a leitura do header, payload e assinatura, valida a assinatura, depois confirma
     * os dados importantes (emissor, app, expiração, email verificado).
     *
     * @return array<string, mixed> o payload decodificado e validado
     * @throws RuntimeException on any validation failure
     */
    public function verify(string $idToken): array
    {
        // O token JWT tem 3 partes separadas por ponto: header.payload.signature.
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new RuntimeException('Formato de token inválido.');
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        // Decodifica header e payload para arrays PHP.
        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        $signature = $this->base64UrlDecode($sigB64);

        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('Token malformado.');
        }

        // O Google usa RS256 para assinar tokens de login.
        if (($header['alg'] ?? '') !== 'RS256') {
            throw new RuntimeException('Algoritmo de assinatura não suportado.');
        }

        // O campo kid identifica qual chave do Google assinou o token.
        $kid = $header['kid'] ?? null;
        if (!$kid) {
            throw new RuntimeException('Token sem identificador de chave.');
        }

        // Busca a chave pública correta para validar a assinatura.
        $publicKeyPem = $this->getPublicKeyForKid((string)$kid);
        $signedInput = $headerB64 . '.' . $payloadB64;

        $pubKey = openssl_pkey_get_public($publicKeyPem);
        if ($pubKey === false) {
            throw new RuntimeException('Falha ao carregar chave pública do Google.');
        }

        // Verifica se a assinatura do token foi feita pela chave correta do Google.
        $verified = openssl_verify($signedInput, $signature, $pubKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new RuntimeException('Assinatura do token inválida.');
        }

        // Confere claims importantes antes de aceitar o login.
        $this->assertClaims($payload);

        return $payload;
    }

    /**
     * Valida as regras do token: emissor, app correta, expiração e email verificado.
     */
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

    /**
     * Pega a chave pública correta da Google usando o valor kid do token.
     */
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
     * Busca as chaves públicas do Google e guarda em cache por 1 hora.
     * Se a rede falhar, tenta usar um cache antigo em vez de quebrar tudo.
     */
    private function fetchJwks(): array
    {
        $cacheFile = $this->cacheFile ?? sys_get_temp_dir() . '/audimage_google_jwks.json';
        $cacheTtl = 3600;

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            $cachedJwks = $this->extractJwks($cached);
            if ($cachedJwks !== null) {
                return $cachedJwks;
            }
        }

        $raw = $this->fetchUrl(self::JWKS_URL);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['keys'])) {
            // Se o Google falhar, tenta usar o cache antigo em vez de parar tudo.
            if (is_file($cacheFile)) {
                $stale = json_decode((string)file_get_contents($cacheFile), true);
                $staleJwks = $this->extractJwks($stale);
                if ($staleJwks !== null) {
                    return $staleJwks;
                }
            }
            throw new RuntimeException('Falha ao obter chaves públicas do Google.');
        }

        @file_put_contents($cacheFile, json_encode($decoded), LOCK_EX);

        return $decoded;
    }

    /**
     * Aceita tanto a resposta bruta do Google quanto o formato usado em testes.
     */
    private function extractJwks(mixed $cached): ?array
    {
        if (!is_array($cached)) {
            return null;
        }

        if (!empty($cached['keys']) && is_array($cached['keys'])) {
            return $cached;
        }

        if (isset($cached['jwks']) && is_array($cached['jwks']) && !empty($cached['jwks']['keys'])) {
            return $cached['jwks'];
        }

        return null;
    }

    /**
     * Faz a requisição HTTP para buscar as chaves públicas do Google.
     * Primeiro tenta cURL; se não existir, usa file_get_contents.
     */
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
     * Converte a chave JWK em formato PEM para que o PHP possa verificar assinatura RSA.
     * Isso não depende de uma biblioteca externa.
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

        // Identificador da chave RSA: OID rsaEncryption + NULL.
        $algorithmIdentifier = $this->derEncodeSequence(
            hex2bin('06092a864886f70d0101010500')
        );

        $publicKeyBitString = "\x00" . $rsaPublicKey;
        $bitString = $this->derEncode(0x03, $publicKeyBitString);

        $spki = $this->derEncodeSequence($algorithmIdentifier . $bitString);

        $base64 = base64_encode($spki);
        $chunks = chunk_split($base64, 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n{$chunks}-----END PUBLIC KEY-----\n";
    }

    /**
     * Converte uma parte inteira em DER para representar números em ASN.1.
     */
    private function derEncodeInteger(string $bin): string
    {
        // Remove zeros à esquerda, mas mantém um zero se o valor for negativo em binário.
        $bin = ltrim($bin, "\x00");
        if ($bin === '') {
            $bin = "\x00";
        }
        if ((ord($bin[0]) & 0x80) !== 0) {
            $bin = "\x00" . $bin;
        }
        return $this->derEncode(0x02, $bin);
    }

    /**
     * Encapsula um bloco em sequence DER.
     */
    private function derEncodeSequence(string $bin): string
    {
        return $this->derEncode(0x30, $bin);
    }

    /**
     * Monta um valor DER usando tag + length + conteúdo.
     */
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

    /**
     * Decodifica um valor base64url usado em JWT.
     */
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
