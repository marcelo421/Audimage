<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\GoogleTokenVerifier;

final class GoogleTokenVerifierTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id.apps.googleusercontent.com';
    private const KID = 'test-kid-1';

    private $privateKey;
    private string $n;
    private string $e;
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->privateKey = openssl_pkey_get_private($this->testPrivateKeyPem());
        $details = openssl_pkey_get_details($this->privateKey);
        $this->n = $this->b64url($details['rsa']['n']);
        $this->e = $this->b64url($details['rsa']['e']);

        $this->cacheFile = sys_get_temp_dir() . '/audimage_test_jwks_' . uniqid() . '.json';
        file_put_contents($this->cacheFile, json_encode([
            'fetched_at' => time(),
            'jwks' => ['keys' => [['kty' => 'RSA', 'kid' => self::KID, 'n' => $this->n, 'e' => $this->e]]],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile);
    }

    public function testValidTokenIsAccepted(): void
    {
        $jwt = $this->buildJwt(['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT_ID, 'email' => 'user@example.com', 'exp' => time() + 3600]);
        $verifier = new GoogleTokenVerifier(self::CLIENT_ID, $this->cacheFile);

        $result = $verifier->verify($jwt);

        $this->assertIsArray($result);
        $this->assertSame('user@example.com', $result['email']);
    }

    public function testWrongAudienceIsRejected(): void
    {
        $jwt = $this->buildJwt(['iss' => 'https://accounts.google.com', 'aud' => 'someone-elses-client-id', 'exp' => time() + 3600]);
        $verifier = new GoogleTokenVerifier(self::CLIENT_ID, $this->cacheFile);

        $this->assertNull($verifier->verify($jwt));
    }

    public function testWrongIssuerIsRejected(): void
    {
        $jwt = $this->buildJwt(['iss' => 'https://evil.example.com', 'aud' => self::CLIENT_ID, 'exp' => time() + 3600]);
        $verifier = new GoogleTokenVerifier(self::CLIENT_ID, $this->cacheFile);

        $this->assertNull($verifier->verify($jwt));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $jwt = $this->buildJwt(['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT_ID, 'exp' => time() - 1000]);
        $verifier = new GoogleTokenVerifier(self::CLIENT_ID, $this->cacheFile);

        $this->assertNull($verifier->verify($jwt));
    }

    public function testAlgNoneIsRejected(): void
    {
        $header = $this->b64url(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => self::KID]));
        $payload = $this->b64url(json_encode(['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT_ID, 'exp' => time() + 3600]));
        $jwt = $header . '.' . $payload . '.';

        $verifier = new GoogleTokenVerifier(self::CLIENT_ID, $this->cacheFile);
        $this->assertNull($verifier->verify($jwt));
    }

    public function testTamperedPayloadFailsSignatureCheck(): void
    {
        $jwt = $this->buildJwt(['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT_ID, 'exp' => time() + 3600]);
        [$headerB64, $payloadB64, $sigB64] = explode('.', $jwt);

        $tampered = json_decode($this->b64urlDecode($payloadB64), true);
        $tampered['aud'] = 'attacker-client-id';
        $tamperedPayloadB64 = $this->b64url(json_encode($tampered));

        $tamperedJwt = $headerB64 . '.' . $tamperedPayloadB64 . '.' . $sigB64;

        $verifier = new GoogleTokenVerifier(self::CLIENT_ID, $this->cacheFile);
        $this->assertNull($verifier->verify($tamperedJwt));
    }

    public function testMalformedTokenIsRejected(): void
    {
        $verifier = new GoogleTokenVerifier(self::CLIENT_ID, $this->cacheFile);
        $this->assertNull($verifier->verify('not-a-valid-jwt'));
    }

    private function buildJwt(array $payloadOverrides): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KID];
        $payload = array_merge([
            'email_verified' => true,
            'iat' => time(),
        ], $payloadOverrides);

        $headerB64 = $this->b64url(json_encode($header));
        $payloadB64 = $this->b64url(json_encode($payload));
        $signingInput = $headerB64 . '.' . $payloadB64;

        openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . $this->b64url($signature);
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        return (string)base64_decode($padded, true);
    }

    private function testPrivateKeyPem(): string
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);
        return $pem;
    }
}
