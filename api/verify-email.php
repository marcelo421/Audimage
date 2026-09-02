<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

/**
 * Intentionally exempt from CSRF validation: this is a GET link clicked
 * from an email client, so there is no session/app context to carry a
 * CSRF header from. The high-entropy, single-use, time-limited token IS
 * the proof of intent here — the same accepted pattern used by password
 * reset links industry-wide.
 *
 * Known trade-off: some corporate email scanners / "safe links" services
 * pre-fetch URLs in emails, which could consume the token before the real
 * user clicks. Impact is limited to a confusing "link already used"
 * experience (not an account compromise) and is recoverable via
 * api/resend-verification.php. A "click to confirm" landing page would
 * close this gap entirely but was out of scope for this pass.
 */
$token = $_GET['token'] ?? '';
$token = is_string($token) ? $token : '';

$verified = false;
if ($token !== '') {
    try {
        $verified = $emailVerificationService->verify($token);
    } catch (\Throwable $e) {
        error_log('verify-email: ' . $e->getMessage());
        $verified = false;
    }
}

$redirectTo = rtrim(getenv('APP_URL') ?: '', '/') . '/index.html?verified=' . ($verified ? '1' : '0');

// Fall back to a relative redirect if APP_URL isn't configured, so this
// still works out of the box in local dev.
if (getenv('APP_URL') === false || getenv('APP_URL') === '') {
    $redirectTo = '../index.html?verified=' . ($verified ? '1' : '0');
}

header('Location: ' . $redirectTo, true, 302);
exit;
