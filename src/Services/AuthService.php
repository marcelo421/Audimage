<?php

declare(strict_types=1);

namespace App\Services;

use App\Repository\UserRepository;
use App\Http\JsonResponder;

class AuthService
{
    public function __construct(private UserRepository $users, private RateLimiter $rateLimiter)
    {
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

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
        ];

        return ['ok' => true, 'user' => $_SESSION['user']];
    }

    public function googleLogin(string $credential): array
    {
        $this->rateLimiter->enforce('google-login', $_SERVER['REMOTE_ADDR'] ?? 'unknown');

        if ($credential === '') {
            JsonResponder::respond(['ok' => false, 'message' => 'Credencial do Google ausente.'], 400);
        }

        $googleClientId = getenv('GOOGLE_CLIENT_ID') ?: '428028486316-ek5l780hfk56p8sekojmfbgutiu1gcjt.apps.googleusercontent.com';
        $tokenInfoUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);

        $payload = $this->fetchUrl($tokenInfoUrl);
        if (!is_array($payload)) {
            JsonResponder::respond(['ok' => false, 'message' => 'Falha ao validar o token do Google.'], 500);
        }

        if (($payload['aud'] ?? '') !== $googleClientId) {
            JsonResponder::respond(['ok' => false, 'message' => 'Token do Google inválido.'], 401);
        }

        if (($payload['email_verified'] ?? '') !== 'true') {
            JsonResponder::respond(['ok' => false, 'message' => 'Email do Google não verificado.'], 401);
        }

        $email = trim($payload['email'] ?? '');
        if ($email === '') {
            JsonResponder::respond(['ok' => false, 'message' => 'Email do Google não encontrado.'], 401);
        }

        $displayName = trim($payload['name'] ?? explode('@', $email)[0]);
        $username = preg_replace('/[^a-zA-Z0-9._-]/', '', $displayName);
        if ($username === '') {
            $username = 'usuario';
        }

        $existingUser = $this->users->findByEmail($email);
        if ($existingUser) {
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

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
        ];

        return ['ok' => true, 'user' => $_SESSION['user']];
    }

    private function fetchUrl(string $url): array|false
    {
        if (function_exists('curl_version')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $result = curl_exec($ch);
            curl_close($ch);
            if ($result === false) {
                return false;
            }
            $decoded = json_decode($result, true);
            return is_array($decoded) ? $decoded : false;
        }

        $result = @file_get_contents($url);
        if ($result === false) {
            return false;
        }

        $decoded = json_decode($result, true);
        return is_array($decoded) ? $decoded : false;
    }
}
