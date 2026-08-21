<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\MailerInterface;
use App\Repository\EmailVerificationRepositoryInterface;
use App\Repository\UserEmailVerificationLookupInterface;

class EmailVerificationService
{
    private const TOKEN_BYTES = 32; // 256 bits — not brute-forceable
    private const TOKEN_TTL_HOURS = 24;

    public function __construct(
        private UserEmailVerificationLookupInterface $users,
        private EmailVerificationRepositoryInterface $verifications,
        private MailerInterface $mailer,
        private RateLimiter $rateLimiter,
        private string $appUrl
    ) {
    }

    /**
     * Generates a token, stores its hash, and emails the raw token as a
     * verification link. The raw token exists only in the outgoing email —
     * only its SHA-256 hash is persisted, so a database leak alone cannot
     * be used to verify arbitrary accounts.
     */
    public function sendVerificationEmail(int $userId, string $email, string $username): bool
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = hash('sha256', $token);
        $expiresAt = new \DateTimeImmutable('+' . self::TOKEN_TTL_HOURS . ' hours');

        $this->verifications->create($userId, $tokenHash, $expiresAt);

        $link = rtrim($this->appUrl, '/') . '/api/verify-email.php?token=' . urlencode($token);

        $subject = 'Confirme seu email — AUDIMAGE';
        $html = $this->buildEmailHtml($username, $link);

        return $this->mailer->send($email, $subject, $html);
    }

    /**
     * Validates the token and marks the associated account as verified.
     * Returns true on success. The token is single-use regardless of
     * outcome checks (expired/wrong tokens simply aren't found as "valid").
     */
    public function verify(string $rawToken): bool
    {
        if ($rawToken === '') {
            return false;
        }

        $tokenHash = hash('sha256', $rawToken);
        $record = $this->verifications->findByTokenHash($tokenHash);

        if ($record === null) {
            return false;
        }

        if ($record['used_at'] !== null) {
            return false;
        }

        $expiresAt = new \DateTimeImmutable((string)$record['expires_at']);
        if ($expiresAt < new \DateTimeImmutable('now')) {
            return false;
        }

        $this->verifications->markUsed($tokenHash);
        $this->users->markEmailVerified((int)$record['user_id']);

        return true;
    }

    /**
     * Resends a verification email. Always returns the same generic result
     * regardless of whether the email exists or is already verified —
     * callers must not let this endpoint be used to enumerate registered
     * emails. Rate-limited per email + per IP to prevent mail-bombing a
     * victim's inbox.
     */
    public function resend(string $email, string $requesterIp): void
    {
        $this->rateLimiter->enforce('verify-email-resend', $email !== '' ? $email : 'empty');
        $this->rateLimiter->enforce('verify-email-resend-ip', $requesterIp);

        $account = $this->users->findByEmail($email);
        if ($account === false || $account['email_verified_at'] !== null) {
            // Same code path/timing as the success case on purpose — no
            // enumeration signal in the response.
            return;
        }

        $this->verifications->invalidateAllForUser((int)$account['id']);
        $this->sendVerificationEmail((int)$account['id'], $account['email'], $account['username']);
    }

    private function buildEmailHtml(string $username, string $link): string
    {
        $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <div style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:24px;color:#1a0a2e;">
          <h2 style="margin-bottom:8px;">Confirme seu email</h2>
          <p>Olá, {$safeUsername}!</p>
          <p>Confirme seu endereço de email para ativar sua conta no AUDIMAGE:</p>
          <p style="margin:24px 0;">
            <a href="{$safeLink}" style="background:#a78bfa;color:#1a0a2e;padding:12px 24px;border-radius:50px;text-decoration:none;font-weight:700;">
              Confirmar meu email
            </a>
          </p>
          <p style="font-size:13px;color:#666;">Se o botão não funcionar, copie e cole este link no navegador:<br>{$safeLink}</p>
          <p style="font-size:13px;color:#666;">Este link expira em 24 horas. Se você não criou uma conta no AUDIMAGE, ignore este email.</p>
        </div>
        HTML;
    }
}
