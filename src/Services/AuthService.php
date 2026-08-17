<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\AuthResult;
use App\Exception\ConflictException;
use App\Exception\InvalidCredentialsException;
use App\Exception\ValidationException;
use App\Repository\UserRepository;

/**
 * Pure domain service: no knowledge of HTTP, JSON, or exit(). Every failure
 * path throws a typed exception under App\Exception, and it's the caller
 * (an api/*.php controller) that decides how to render it as a response.
 * This makes the class trivially unit-testable and reusable outside of a
 * web request (CLI tools, queued jobs, etc).
 */
class AuthService
{
    // Keep validation rules in one place, shared by register() and any
    // future caller, so the API never drifts from what the client shows.
    public const PASSWORD_MIN_LENGTH = 8;

    public function __construct(
        private UserRepository $users,
        private RateLimiter $rateLimiter,
    ) {
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

        if (!self::isPasswordStrongEnough($password)) {
            throw new ValidationException(
                'A senha precisa ter pelo menos ' . self::PASSWORD_MIN_LENGTH . ' caracteres, incluindo letras e números.'
            );
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

    public function googleLogin(string $email, string $displayName): AuthResult
    {
        $this->rateLimiter->enforce('google-login', $_SERVER['REMOTE_ADDR'] ?? 'unknown');

        $email = trim($email);
        if ($email === '') {
            throw new ValidationException('Email do Google não encontrado.');
        }

        $existingUser = $this->users->findByEmail($email);
        if ($existingUser) {
            $_SESSION['user'] = [
                'id' => (int)$existingUser['id'],
                'username' => $existingUser['username'],
                'email' => $existingUser['email'],
            ];
            return new AuthResult($_SESSION['user']);
        }

        $username = preg_replace('/[^a-zA-Z0-9._-]/', '', trim($displayName) ?: explode('@', $email)[0]);
        if ($username === '') {
            $username = 'usuario';
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

    public static function isPasswordStrongEnough(string $password): bool
    {
        return strlen($password) >= self::PASSWORD_MIN_LENGTH
            && preg_match('/[A-Za-z]/', $password) === 1
            && preg_match('/\d/', $password) === 1;
    }
}
