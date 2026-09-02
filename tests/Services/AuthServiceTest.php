<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\AuthService;
use App\Services\RateLimiter;
use App\Repository\UserRepository;
use App\Exception\ValidationException;
use App\Exception\InvalidCredentialsException;
use App\Exception\ConflictException;

/**
 * Demonstrates the payoff of decoupling AuthService from JsonResponder/exit():
 * it can now be exercised with plain mocks, no HTTP context, no session
 * superglobals wired through a web server, no process termination to work
 * around. This was NOT possible with the previous implementation.
 */
final class AuthServiceTest extends TestCase
{
    private UserRepository $users;
    private RateLimiter $rateLimiter;
    private AuthService $auth;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];

        $this->users = $this->createMock(UserRepository::class);
        // RateLimiter::enforce() is void and simply not called in these
        // tests unless we want it to throw — mocking it isolates AuthService
        // from needing a real Redis connection.
        $this->rateLimiter = $this->createMock(RateLimiter::class);

        $this->auth = new AuthService($this->users, $this->rateLimiter);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        $_SESSION = [];
    }

    public function testLoginThrowsValidationExceptionForEmptyFields(): void
    {
        $this->expectException(ValidationException::class);
        $this->auth->login('', '');
    }

    public function testLoginThrowsInvalidCredentialsForUnknownUser(): void
    {
        $this->users->method('findByUsernameOrEmail')->willReturn(false);

        $this->expectException(InvalidCredentialsException::class);
        $this->auth->login('ghost', 'whatever123');
    }

    public function testLoginThrowsInvalidCredentialsForWrongPassword(): void
    {
        $this->users->method('findByUsernameOrEmail')->willReturn([
            'id' => 1,
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password_hash' => password_hash('correct-horse', PASSWORD_DEFAULT),
            'email_verified_at' => '2026-01-01 00:00:00',
        ]);

        $this->expectException(InvalidCredentialsException::class);
        $this->auth->login('alice', 'wrong-password');
    }

    public function testLoginRejectsUnverifiedEmail(): void
    {
        $this->users->method('findByUsernameOrEmail')->willReturn([
            'id' => 1,
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password_hash' => password_hash('correct-horse', PASSWORD_DEFAULT),
            'email_verified_at' => null,
        ]);

        $this->expectException(InvalidCredentialsException::class);
        $this->auth->login('alice', 'correct-horse');
    }

    public function testLoginSucceedsAndPopulatesSession(): void
    {
        $this->users->method('findByUsernameOrEmail')->willReturn([
            'id' => 1,
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password_hash' => password_hash('correct-horse', PASSWORD_DEFAULT),
            'email_verified_at' => '2026-01-01 00:00:00',
        ]);

        $result = $this->auth->login('alice', 'correct-horse');

        $this->assertSame('alice', $result->user['username']);
        $this->assertSame(1, $result->user['id']);
        $this->assertSame('alice', $_SESSION['user']['username']);
    }

    public function testRegisterRejectsWeakPassword(): void
    {
        $this->users->method('existsByUsernameOrEmail')->willReturn(false);

        $this->expectException(ValidationException::class);
        $this->auth->register('bob', 'bob@example.com', 'short');
    }

    public function testRegisterDoesNotCreateAuthenticatedSessionBeforeEmailVerification(): void
    {
        $this->users->method('existsByUsernameOrEmail')->willReturn(false);
        $this->users->method('createUser')->willReturn(42);

        $result = $this->auth->register('bob', 'bob@example.com', 'password123');

        $this->assertSame(42, $result->user()['id']);
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testRegisterRejectsInvalidEmail(): void
    {
        $this->expectException(ValidationException::class);
        $this->auth->register('bob', 'not-an-email', 'password123');
    }

    public function testRegisterThrowsConflictForDuplicateUsername(): void
    {
        $this->users->method('existsByUsernameOrEmail')->willReturn(true);
        $this->users->method('findByUsernameOrEmail')->willReturn([
            'id' => 5, 'username' => 'bob', 'email' => 'other@example.com',
        ]);

        $this->expectException(ConflictException::class);
        $this->auth->register('bob', 'bob@example.com', 'password123');
    }

    public function testIsPasswordStrongEnough(): void
    {
        $this->assertFalse(AuthService::isPasswordStrongEnough('short1'));
        $this->assertFalse(AuthService::isPasswordStrongEnough('onlyletters'));
        $this->assertFalse(AuthService::isPasswordStrongEnough('12345678'));
        $this->assertTrue(AuthService::isPasswordStrongEnough('password123'));
    }
}
