<?php

declare(strict_types=1);

namespace App\Services;

use App\Repository\UserRepository;
use App\Exception\ValidationException;
use App\Exception\InvalidCredentialsException;
use App\Exception\ConflictException;
use App\Exception\ExternalServiceException;

class AuthService
{
    public function __construct(private UserRepository $users, private RateLimiter $rateLimiter)
    {
    }

    public function login(string $user, string $password): AuthResult
    {
        $this->rateLimiter->enforce('login', $user !== '' ? $user : 'empty');

        if ($user === '' || $password === '') {
            throw new ValidationException('Preencha todos os campos.');
        }

        $account = $this->users->findByUsernameOrEmail($user);
        if (!$account || !password_verify($password, $account['password_hash'])) {
            throw new InvalidCredentialsException('Usuário ou senha inválidos.');
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$account['id'],
            'username' => $account['username'],
            'email' => $account['email'],
        ];

        return new AuthResult($_SESSION['user']);
    }

    public function register(string $username, string $email, string $password): AuthResult
    {
        $this->rateLimiter->enforce('register', $email !== '' ? $email : 'empty');

        if ($username === '' || $email === '' || $password === '') {
            throw new ValidationException('Preencha todos os campos.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Email inválido.');
        }

        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new ValidationException('A senha precisa ter pelo menos 8 caracteres, incluindo letras e números.');
        }

        if ($this->users->existsByUsernameOrEmail($username, $email)) {
            $existing = $this->users->findByUsernameOrEmail($username) ?: $this->users->findByUsernameOrEmail($email);
            if ($existing && $existing['username'] === $username) {
                throw new ConflictException('Esse nome de usuário já existe.');
            }
            throw new ConflictException('Esse email já está cadastrado.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $userId = $this->users->createUser($username, $email, $hash);

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
        ];

        return new AuthResult($_SESSION['user']);
    }

    public function googleLogin(string $credential): AuthResult
    {
        $this->rateLimiter->enforce('google-login', $this->clientIdentifier());

        if ($credential === '') {
            throw new ValidationException('Credencial do Google ausente.');
        }

        $googleClientId = getenv('GOOGLE_CLIENT_ID');
        if (!$googleClientId) {
            error_log('[AUTH] GOOGLE_CLIENT_ID não configurado no ambiente.');
            throw new ExternalServiceException('Login com Google não configurado.');
        }

        try {
            $verifier = new GoogleTokenVerifier($googleClientId);
            $payload = $verifier->verify($credential);
        } catch (\Throwable $e) {
            error_log('[AUTH] Falha na validação do token Google: ' . $e->getMessage());
            throw new InvalidCredentialsException('Token do Google inválido.');
        }

        $email = trim($payload['email'] ?? '');
        if ($email === '') {
            throw new InvalidCredentialsException('Email do Google não encontrado.');
        }

        $displayName = trim($payload['name'] ?? explode('@', $email)[0]);
        $username = preg_replace('/[^a-zA-Z0-9._-]/', '', $displayName);
        if ($username === '') {
            $username = 'usuario';
        }

        $existingUser = $this->users->findByEmail($email);
        if ($existingUser) {
            // Regenerate the session id on every successful authentication,
            // including for returning users — otherwise a pre-auth session id
            // could be fixated and reused post-login.
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$existingUser['id'],
                'username' => $existingUser['username'],
                'email' => $existingUser['email'],
            ];
            return new AuthResult($_SESSION['user']);
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

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
        ];

        return new AuthResult($_SESSION['user']);
    }

    private function clientIdentifier(): string
    {
        $trustProxy = getenv('TRUST_PROXY_HEADERS') === 'true';
        if ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
