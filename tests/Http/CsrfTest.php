<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Http\Csrf;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        $_SESSION = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testEnsureTokenCreatesToken(): void
    {
        $token = Csrf::ensureToken();
        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token));
        $this->assertEquals($token, $_SESSION['csrf_token']);
    }

    public function testGetTokenReturnsNullIfNotSet(): void
    {
        unset($_SESSION['csrf_token']);
        $this->assertNull(Csrf::getToken());
    }

    public function testValidateRequestSucceedsWithMatchingHeader(): void
    {
        $token = Csrf::ensureToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $this->assertTrue(Csrf::validateRequest());
    }

    public function testValidateRequestFailsWithMissingHeader(): void
    {
        Csrf::ensureToken();
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $this->assertFalse(Csrf::validateRequest());
    }

    public function testValidateRequestFailsWithWrongHeader(): void
    {
        Csrf::ensureToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'invalid';
        $this->assertFalse(Csrf::validateRequest());
    }
}
