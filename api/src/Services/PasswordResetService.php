<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\MailerInterface;
use App\Repository\PasswordResetRepositoryInterface;
use App\Repository\UserPasswordResetLookupInterface;

class PasswordResetService
{
    private const TOKEN_BYTES = 32; // 256 bits — not brute-forceable
    private const TOKEN_TTL_MINUTES = 30;

    public function __construct(
        private UserPasswordResetLookupInterface $users,
        private PasswordResetRepositoryInterface $resets,
        private MailerInterface $mailer,
        private RateLimiter $rateLimiter,
        private string $appUrl
    ) {
    }

    /**
     * Requests a password reset. Always completes without revealing whether
     * the email is registered — same timing/response shape either way, so
     * this endpoint can't be used to enumerate accounts. Rate-limited per
     * email AND per IP, on a scope dedicated to this flow (separate from
     * login/register limits) so a burst of reset requests can't also lock
     * out legitimate login attempts for the same identifier, and vice versa.
     */
    public function requestReset(string $email, string $requesterIp): void
    {
        $this->rateLimiter->enforce('password-reset-request', $email !== '' ? $email : 'empty');
        $this->rateLimiter->enforce('password-reset-request-ip', $requesterIp);

        $account = $this->users->findByEmail($email);
        if ($account === false) {
            // No such account — do the same amount of "work" conceptually
            // and return the same result. No email is sent, no error surfaces.
            return;
        }

        $this->resets->invalidateAllForUser((int)$account['id']);

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = hash('sha256', $token);
        $expiresAt = new \DateTimeImmutable('+' . self::TOKEN_TTL_MINUTES . ' minutes');

        $this->resets->create((int)$account['id'], $tokenHash, $expiresAt);

        $link = rtrim($this->appUrl, '/') . '/index.html?reset_token=' . urlencode($token);
        $subject = 'Redefinição de senha — AUDIMAGE';
        $html = $this->buildEmailHtml($account['username'], $link);

        $this->mailer->send($account['email'], $subject, $html);
    }

    /**
     * Validates the token and, if valid, applies the new password. Returns
     * a result array — callers map this to the appropriate HTTP status.
     * Deliberately returns a generic "invalid or expired" failure reason
     * regardless of *why* the token failed (wrong, expired, already used),
     * so a caller can't use the error message to probe token validity.
     */
    public function resetPassword(string $rawToken, string $newPassword): array
    {
        if ($rawToken === '') {
            return ['ok' => false, 'message' => 'Token inválido ou expirado.'];
        }

        if (strlen($newPassword) < 8 || !preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
            return ['ok' => false, 'message' => 'A senha precisa ter pelo menos 8 caracteres, incluindo letras e números.'];
        }

        $tokenHash = hash('sha256', $rawToken);
        $record = $this->resets->findByTokenHash($tokenHash);

        if ($record === null || $record['used_at'] !== null) {
            return ['ok' => false, 'message' => 'Token inválido ou expirado.'];
        }

        $expiresAt = new \DateTimeImmutable((string)$record['expires_at']);
        if ($expiresAt < new \DateTimeImmutable('now')) {
            return ['ok' => false, 'message' => 'Token inválido ou expirado.'];
        }

        $userId = (int)$record['user_id'];

        $this->resets->markUsed($tokenHash);
        // Belt-and-suspenders: kill any other still-valid reset tokens for
        // this user too, not just the one that was used.
        $this->resets->invalidateAllForUser($userId);

        $this->users->updatePasswordHash($userId, password_hash($newPassword, PASSWORD_DEFAULT));

        // Successfully receiving and using a reset link proves control of
        // the inbox — treat that as equivalent proof to clicking the
        // dedicated verification link, so a user isn't left stuck
        // "password reset, but still can't log in because unverified".
        $this->users->markEmailVerified($userId);

        return ['ok' => true, 'message' => 'Senha redefinida com sucesso. Faça login com a nova senha.'];
    }

    private function buildEmailHtml(string $username, string $link): string
    {
        $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <div style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:24px;color:#1a0a2e;">
          <h2 style="margin-bottom:8px;">Redefinir senha</h2>
          <p>Olá, {$safeUsername}!</p>
          <p>Recebemos um pedido para redefinir a senha da sua conta no AUDIMAGE. Se foi você, clique abaixo:</p>
          <p style="margin:24px 0;">
            <a href="{$safeLink}" style="background:#a78bfa;color:#1a0a2e;padding:12px 24px;border-radius:50px;text-decoration:none;font-weight:700;">
              Redefinir minha senha
            </a>
          </p>
          <p style="font-size:13px;color:#666;">Se o botão não funcionar, copie e cole este link no navegador:<br>{$safeLink}</p>
          <p style="font-size:13px;color:#666;">Este link expira em 30 minutos. Se você não pediu essa redefinição, ignore este email — sua senha continua a mesma.</p>
        </div>
        HTML;
    }
}
