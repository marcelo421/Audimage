<?php
declare(strict_types=1);

use App\Http\JsonResponder;
use App\Repository\UserRepository;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponder::respond(['ok' => false, 'message' => 'Método não permitido.'], 405);
}

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret = (string)(getenv('STRIPE_WEBHOOK_SECRET') ?: '');

if ($payload === false || $secret === '' || !verifyStripeSignature($payload, $signature, $secret)) {
    JsonResponder::respond(['ok' => false, 'message' => 'Webhook inválido.'], 400);
}

$event = json_decode($payload, true);
if (!is_array($event) || !is_array($event['data']['object'] ?? null)) {
    JsonResponder::respond(['ok' => false, 'message' => 'Payload inválido.'], 400);
}

$object = $event['data']['object'];
$type = (string)($event['type'] ?? '');
$email = trim((string)($object['customer_details']['email'] ?? $object['customer_email'] ?? ''));
$customerId = is_string($object['customer'] ?? null) ? $object['customer'] : null;
$subscriptionId = is_string($object['subscription'] ?? null) ? $object['subscription'] : null;

$status = match ($type) {
    'checkout.session.completed', 'invoice.paid' => 'active',
    'customer.subscription.deleted', 'customer.subscription.paused' => 'inactive',
    'invoice.payment_failed' => 'past_due',
    default => null,
};

if ($status !== null) {
    $pdo = App\Database\Connection::createFromEnv();
    $users = new UserRepository($pdo);
    $periodEnd = isset($object['current_period_end']) ? date('Y-m-d H:i:s', (int)$object['current_period_end']) : null;
    if ($email !== '') {
        $users->updateSubscriptionByEmail($email, $status, $customerId, $subscriptionId, $periodEnd);
    } elseif ($customerId !== null) {
        $users->updateSubscriptionByCustomerId($customerId, $status, $subscriptionId, $periodEnd);
    }
}

JsonResponder::respond(['ok' => true]);

function verifyStripeSignature(string $payload, string $header, string $secret): bool
{
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $item) {
        [$key, $value] = array_pad(explode('=', $item, 2), 2, '');
        if ($key === 't') {
            $timestamp = $value;
        } elseif ($key === 'v1') {
            $signatures[] = $value;
        }
    }

    if ($timestamp === null || $signatures === [] || !ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }
    return false;
}