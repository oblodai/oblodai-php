<?php

// What the README's code blocks take from the reader's own application: the keys, the incoming
// webhook request and two functions of theirs. tests/Contract/ReadmeTest.php runs the blocks after
// this prelude, against the mock gateway.
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

$publicId = (string) getenv('OBLODAI_PUBLIC_ID');
$secret = (string) getenv('OBLODAI_SECRET');

// A signed payment delivery, as `file_get_contents('php://input')` and `getallheaders()` give it.
$rawBody = (string) json_encode(Oblodai\Tests\Support\Samples::of(
    Oblodai\Generated\Model\PaymentWebhook::class,
    ['type' => 'payment', 'status' => 'paid', 'order_id' => 'order-1001'],
));
$headers = [
    'X-Webhook-Timestamp' => (string) time(),
    'X-Webhook-Signature' => Oblodai\Core\Signer::signWebhook((string) getenv('OBLODAI_WEBHOOK_SECRET'), time(), $rawBody),
];

// A PSR-18 client and PSR-17 factories, for the HTTP stack section.
$requestFactory = $streamFactory = new Nyholm\Psr7\Factory\Psr17Factory();
$client = new class () implements Psr\Http\Client\ClientInterface {
    public function sendRequest(Psr\Http\Message\RequestInterface $request): Psr\Http\Message\ResponseInterface
    {
        throw new RuntimeException('the README never sends through this client');
    }
};

function markOrderPaid(string $orderId): void
{
    echo "marked {$orderId} paid\n";
}

function scheduleRetry(int $seconds): void
{
}
