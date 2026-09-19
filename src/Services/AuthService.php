<?php

declare(strict_types=1);

namespace App\Services;

use App\Repository\UserRepository;
use App\Exception\ValidationException;
use App\Exception\InvalidCredentialsException;
use App\Exception\ConflictException;
use App\Exception\ExternalServiceException;
use App\Exception\PaymentRequiredException;
use App\Domain\AuthResult;

class AuthService
{
    /**
     * Recebe o repositório de usuários e o limitador de requisições.
     * Em termos simples: esta classe coordena login, cadastro e regras de acesso.
     */
    public function __construct(private UserRepository $users, private RateLimiter $rateLimiter)
    {
    }

    /**
     * Faz o login do usuário.
     * Verifica se o usuário existe, se a senha está correta, se o email foi confirmado
     * e se a conta pode entrar de acordo com a assinatura.
     */
    public function login(string $user, string $password): AuthResult
    {
        // Protege contra abuso: limita tentativas de login por usuário ou endereço IP.
        $this->rateLimiter->enforce('login', $user !== '' ? $user : 'empty');

        // Evita login vazio.
        if ($user === '' || $password === '') {
            throw new ValidationException('Preencha todos os campos.');
        }

        // Busca a conta no banco e compara a senha em hash.
        $account = $this->users->findByUsernameOrEmail($user);
        if (!$account || !password_verify($password, $account['password_hash'])) {
            throw new InvalidCredentialsException('Usuário ou senha inválidos.');
        }

        // Usuário não pode entrar sem confirmar o email.
        if (($account['email_verified_at'] ?? null) === null || $account['email_verified_at'] === '') {
            throw new InvalidCredentialsException('Confirme seu email antes de entrar.');
        }

        // Verifica se a assinatura está ativa ou se o usuário é o admin especial.
        $this->assertSubscriptionAccess($account);

        // Regenera o ID da sessão para evitar roubo de sessão.
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$account['id'],
            'username' => $account['username'],
            'email' => $account['email'],
        ];

        return new AuthResult($_SESSION['user']);
    }

    /**
     * Cria uma nova conta.
     * Valida dados, impede duplicidade e grava o usuário no banco.
     */
    public function register(string $username, string $email, string $password): AuthResult
    {
        // Limita criação de contas para evitar spam.
        $this->rateLimiter->enforce('register', $email !== '' ? $email : 'empty');

        // Nada pode ficar vazio.
        if ($username === '' || $email === '' || $password === '') {
            throw new ValidationException('Preencha todos os campos.');
        }

        // Valida o formato do email.
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Email inválido.');
        }

        // A senha precisa ter pelo menos 8 caracteres com letras e números.
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new ValidationException('A senha precisa ter pelo menos 8 caracteres, incluindo letras e números.');
        }

        // Não deixa criar usuário com username ou email duplicado.
        if ($this->users->existsByUsernameOrEmail($username, $email)) {
            $existing = $this->users->findByUsernameOrEmail($username) ?: $this->users->findByUsernameOrEmail($email);
            if ($existing && $existing['username'] === $username) {
                throw new ConflictException('Esse nome de usuário já existe.');
            }
            throw new ConflictException('Esse email já está cadastrado.');
        }

        // Gera um hash seguro em vez de salvar a senha em texto puro.
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $userId = $this->users->createUser($username, $email, $hash);

        return new AuthResult([
            'id' => $userId,
            'username' => $username,
            'email' => $email,
        ]);
    }

    /**
     * Login com Google.
     * Valida o token enviado pelo Google, confirma a identidade do usuário e entra no sistema.
     */
    public function googleLogin(string $credential): AuthResult
    {
        // Limita tentativas de login por Google para evitar abuso.
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
            // Valida assinatura e claims do token no Google usando a classe específica.
            $verifier = new GoogleTokenVerifier($googleClientId);
            $payload = $verifier->verify($credential);
        } catch (\Throwable $e) {
            error_log('[AUTH] Falha na validação do token Google: ' . $e->getMessage());
            throw new InvalidCredentialsException('Token do Google inválido.');
        }

        // Extrai o email do payload retornado pela Google.
        $email = trim($payload['email'] ?? '');
        if ($email === '') {
            throw new InvalidCredentialsException('Email do Google não encontrado.');
        }

        // Monta um nome de usuário a partir do nome do Google.
        $displayName = trim($payload['name'] ?? explode('@', $email)[0]);
        $username = preg_replace('/[^a-zA-Z0-9._-]/', '', $displayName);
        if ($username === '') {
            $username = 'usuario';
        }

        // Se o usuário já existe, apenas atualiza estado de verificação e entra.
        $existingUser = $this->users->findByEmail($email);
        if ($existingUser) {
            if (($existingUser['email_verified_at'] ?? null) === null || $existingUser['email_verified_at'] === '') {
                $this->users->markEmailVerified((int)$existingUser['id']);
            }

            $this->assertSubscriptionAccess($existingUser);

            // Garante sessão nova para evitar fixação por ID de sessão.
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$existingUser['id'],
                'username' => $existingUser['username'],
                'email' => $existingUser['email'],
            ];
            return new AuthResult($_SESSION['user']);
        }

        // Se o usuário ainda não existe, cria um login com username único.
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

        // Cria uma conta de login social sem senha tradicional.
        $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $userId = $this->users->createUser($username, $email, $passwordHash);
        $this->users->markEmailVerified($userId);

        $this->assertSubscriptionAccess([
            'username' => $username,
            'subscription_status' => 'inactive',
        ]);

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
        ];

        return new AuthResult($_SESSION['user']);
    }

    /**
     * Descobre o identificador do cliente para aplicar rate limiting.
     * Se houver proxy, usa o IP original; caso contrário, usa o IP direto do cliente.
     */
    private function clientIdentifier(): string
    {
        $trustProxy = getenv('TRUST_PROXY_HEADERS') === 'true';
        if ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Regras de acesso por assinatura.
     * O usuário administrador é exceção; demais usuários precisam de assinatura ativa ou trial.
     */
    private function assertSubscriptionAccess(array $account): void
    {
        if (($account['username'] ?? '') === 'adm_audimage') {
            return;
        }

        if (!in_array($account['subscription_status'] ?? 'inactive', ['active', 'trialing'], true)) {
            throw new PaymentRequiredException();
        }
    }
}
