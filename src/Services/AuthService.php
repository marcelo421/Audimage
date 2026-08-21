<?php

declare(strict_types=1);

namespace App\Services;

use App\Repository\UserRepository;
use App\Http\JsonResponder;

class AuthService
{
    public function __construct(
        private UserRepository $users,
        private RateLimiter $rateLimiter,
        private GoogleTokenVerifier $googleTokenVerifier,
        private EmailVerificationService $emailVerification
    ) {
    }

    public function login(string $user, string $password): array
    {
        $this->rateLimiter->enforce('login', $user !== '' ? $user : 'empty');

        if ($user === '' || $password === '') {
            JsonResponder::respond(['ok' => false, 'message' => 'Preencha todos os campos.'], 400);
        }

        $account = $this->users->findByUsernameOrEmail($user);
        if (!$account || !password_verify($password, $account['password_hash'])) {
            JsonResponder::respond(['ok' => false, 'message' => 'Usuário ou senha inválidos.'], 401);
        }

        if ($account['email_verified_at'] === null) {
            JsonResponder::respond([
                'ok' => false,
                'message' => 'Confirme seu email antes de entrar. Verifique sua caixa de entrada.',
                'requires_verification' => true,
                'email' => $account['email'],
            ], 403);
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$account['id'],
            'username' => $account['username'],
            'email' => $account['email'],
        ];

        return ['ok' => true, 'user' => $_SESSION['user']];
    }

    public function register(string $username, string $email, string $password): array
    {
        $this->rateLimiter->enforce('register', $email !== '' ? $email : 'empty');

        if ($username === '' || $email === '' || $password === '') {
            JsonResponder::respond(['ok' => false, 'message' => 'Preencha todos os campos.'], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            JsonResponder::respond(['ok' => false, 'message' => 'Email inválido.'], 400);
        }

        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            JsonResponder::respond(['ok' => false, 'message' => 'A senha precisa ter pelo menos 8 caracteres, incluindo letras e números.'], 400);
        }

        if ($this->users->existsByUsernameOrEmail($username, $email)) {
            $existing = $this->users->findByUsernameOrEmail($username) ?: $this->users->findByUsernameOrEmail($email);
            if ($existing && $existing['username'] === $username) {
                JsonResponder::respond(['ok' => false, 'message' => 'Esse nome de usuário já existe.'], 409);
            }
            JsonResponder::respond(['ok' => false, 'message' => 'Esse email já está cadastrado.'], 409);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $userId = $this->users->createUser($username, $email, $hash);

        // Registration does NOT log the user in. Email/password accounts
        // start unverified (email_verified_at IS NULL) and login() blocks
        // until the link is clicked — decided in favor of "block", not
        // "allow with a warning", because an unconfirmed email means we
        // haven't established the user actually controls that inbox, and
        // silently allowing access just defers the check to whenever
        // someone notices.
        $this->emailVerification->sendVerificationEmail($userId, $email, $username);

        return [
            'ok' => true,
            'requires_verification' => true,
            'message' => 'Cadastro realizado! Confirme seu email para entrar.',
        ];
    }

    public function googleLogin(string $credential): array
    {
        $this->rateLimiter->enforce('google-login', $_SERVER['REMOTE_ADDR'] ?? 'unknown');

        if ($credential === '') {
            JsonResponder::respond(['ok' => false, 'message' => 'Credencial do Google ausente.'], 400);
        }

        // Local RS256 verification against Google's published JWKS — no
        // dependency on the debug-only `tokeninfo` endpoint, which is not
        // meant for production traffic and has no availability guarantee.
        $payload = $this->googleTokenVerifier->verify($credential);
        if ($payload === null) {
            JsonResponder::respond(['ok' => false, 'message' => 'Token do Google inválido.'], 401);
        }

        $emailVerified = $payload['email_verified'] ?? false;
        if ($emailVerified !== true && $emailVerified !== 'true') {
            JsonResponder::respond(['ok' => false, 'message' => 'Email do Google não verificado.'], 401);
        }

        $email = trim((string)($payload['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            JsonResponder::respond(['ok' => false, 'message' => 'Email do Google não encontrado.'], 401);
        }

        $displayName = trim((string)($payload['name'] ?? explode('@', $email)[0]));
        $username = preg_replace('/[^a-zA-Z0-9._-]/', '', $displayName);
        if ($username === '') {
            $username = 'usuario';
        }

        $existingUser = $this->users->findByEmail($email);
        if ($existingUser) {
            // Google itself just vouched for ownership of this email — at
            // least as strong a proof as clicking our own confirmation
            // link. If the account was stuck unverified (e.g. the user
            // never clicked the original email), this unblocks it instead
            // of leaving them stranded.
            if ($existingUser['email_verified_at'] === null) {
                $this->users->markEmailVerified((int)$existingUser['id']);
            }

            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$existingUser['id'],
                'username' => $existingUser['username'],
                'email' => $existingUser['email'],
            ];
            return ['ok' => true, 'user' => $_SESSION['user']];
        }

        $baseUsername = $username;
        $counter = 1;
        while ($this->users->findByUsername($username)) {
            $username = $baseUsername . $counter;
            $counter++;
            if ($counter > 100) {
                $username = $baseUsername . bin2hex(random_bytes(4));
                break;
            }
        }

        $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $userId = $this->users->createUser($username, $email, $passwordHash);
        // No confirmation email needed — Google already verified this address.
        $this->users->markEmailVerified($userId);

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
        ];

        return ['ok' => true, 'user' => $_SESSION['user']];
    }
}
