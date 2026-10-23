<?php
// ---------------------------------------------------------------
// order-app (PRODUCER)
// Shows a "Place Order" button. Each click publishes one message
// to the "orders" queue and returns immediately — it does NOT wait
// for the worker to process the order.
// ---------------------------------------------------------------

require __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = generateOrderId();

    try {
        publishOrder($orderId);
        $_SESSION['flash'] = ['type' => 'success', 'order_id' => $orderId];
    } catch (Throwable $e) {
        error_log("Publish failed for $orderId: " . $e->getMessage());
        $_SESSION['flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    }

    // Post/Redirect/Get: redirect to a normal GET page, so pressing
    // refresh does not submit another order.
    header('Location: /', true, 303);
    exit;
}

// Show the result of the last POST once, then forget it.
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/**
 * Random ID like ORD-A7K2 (no look-alike characters such as 0/O or 1/I).
 */
function generateOrderId(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $id = '';
    for ($i = 0; $i < 4; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return 'ORD-' . $id;
}

/**
 * Send one order to RabbitMQ and wait until RabbitMQ confirms it.
 * Throws an exception if anything goes wrong.
 */
function publishOrder(string $orderId): void
{
    $connection = new AMQPStreamConnection(
        getenv('RABBITMQ_HOST'),   // "rabbitmq" = Docker service name
        5672,
        getenv('RABBITMQ_USER'),
        getenv('RABBITMQ_PASS')
    );
    $channel = $connection->channel();

    try {
        // Durable queue: the queue itself survives a RabbitMQ restart.
        $channel->queue_declare(
            'orders',  // name: queue name
            false,     // passive: false = create the queue if it doesn't exist
            true,      // durable: queue survives a RabbitMQ restart
            false,     // exclusive: false = other connections (the worker) can use it
            false,     // auto_delete: false = keep the queue when the last consumer disconnects
            false,     // nowait: false = wait for RabbitMQ to confirm the queue was declared
            [],        // arguments: extra options (e.g. x-message-ttl, x-dead-letter-exchange, x-max-length)
            null       // ticket: legacy access ticket, unused in modern RabbitMQ (always null)
        );

        // Publisher confirms: RabbitMQ will tell us ack (accepted) or nack (rejected).
        $confirmed = false;
        $channel->set_ack_handler(function () use (&$confirmed) {
            $confirmed = true;
        });
        $channel->set_nack_handler(function () use (&$confirmed) {
            $confirmed = false;
        });
        $channel->confirm_select();

        $body = json_encode([
            'order_id'   => $orderId,
            'created_at' => date(DATE_ATOM),
        ]);

        // Persistent message: stored on disk, so it survives a RabbitMQ restart.
        $message = new AMQPMessage($body, [
            'content_type'  => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        // Default exchange ('') routes straight to the queue named "orders".
        $channel->basic_publish($message, '', 'orders');

        // Block until RabbitMQ answers (max 5 seconds).
        $channel->wait_for_pending_acks(5);

        if (!$confirmed) {
            throw new RuntimeException('RabbitMQ did not accept the message.');
        }
    } finally {
        $channel->close();
        $connection->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order App</title>
    <link rel="icon" href="data:,">
    <style>
        body { font-family: system-ui, sans-serif; background: #f4f5f7; color: #222;
               display: flex; justify-content: center; padding: 60px 16px; margin: 0; }
        .card { background: #fff; border-radius: 12px; padding: 32px; max-width: 440px;
                width: 100%; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
        h1 { margin: 0 0 8px; font-size: 24px; }
        p { color: #555; line-height: 1.5; }
        button { width: 100%; padding: 14px; font-size: 17px; border: 0; border-radius: 8px;
                 background: #ff6600; color: #fff; cursor: pointer; }
        button:disabled { opacity: .6; cursor: wait; }
        .box { border-radius: 8px; padding: 14px 16px; margin-top: 20px; }
        .success { background: #e7f6ec; border: 1px solid #9fd8b0; }
        .error   { background: #fdecec; border: 1px solid #f0a8a8; }
        .pending { background: #f6f7f9; border: 1px dashed #c4c8cf; color: #555; }
        code { background: #eee; padding: 1px 5px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="card">
    <h1>🛒 Order App</h1>
    <p>Click the button to place an order. It is sent to RabbitMQ, and a worker processes it in the background.</p>

    <form method="post" onsubmit="this.querySelector('button').disabled = true">
        <button type="submit">Place Order</button>
    </form>

    <?php if ($flash && $flash['type'] === 'success'): ?>
        <div class="box success">
            <strong>✅ Order queued</strong><br>
            Order ID: <strong><?= htmlspecialchars($flash['order_id']) ?></strong><br>
            <small>RabbitMQ confirmed it has stored the message.</small>
        </div>
        <div class="box pending">
            <strong>⏳ Processing</strong><br>
            The worker handles this order in the background (about 3 seconds).
            Check the worker logs or <code>output/orders.txt</code>.
        </div>
    <?php elseif ($flash && $flash['type'] === 'error'): ?>
        <div class="box error">
            <strong>❌ Order NOT queued</strong><br>
            Could not publish to RabbitMQ: <?= htmlspecialchars($flash['message']) ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
