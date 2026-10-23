<?php
// ---------------------------------------------------------------
// worker (CONSUMER)
// Runs forever, takes orders from the "orders" queue one at a time,
// writes each one to output/orders.txt, then sends an ACK.
// ---------------------------------------------------------------

require __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

const OUTPUT_FILE = __DIR__ . '/../output/orders.txt';

// Which worker this is (set in docker-compose.yml), so you can see who handled each order.
define('WORKER_NAME', getenv('WORKER_NAME') ?: 'worker');

function logLine(string $text): void
{
    echo '[' . date('H:i:s') . '] [' . WORKER_NAME . "] $text" . PHP_EOL;
}

$connection = new AMQPStreamConnection(
    getenv('RABBITMQ_HOST'),
    5672,
    getenv('RABBITMQ_USER'),
    getenv('RABBITMQ_PASS')
);
$channel = $connection->channel();

// Same declaration as the producer (durable queue). Declaring twice is safe.
$channel->queue_declare(
    'orders',  // name: queue name
    false,     // passive: false = create the queue if it doesn't exist
    true,      // durable: queue survives a RabbitMQ restart
    false,     // exclusive: false = other connections (the producer) can use it
    false,     // auto_delete: false = keep the queue when the last consumer disconnects
    false,     // nowait: false = wait for RabbitMQ to confirm the queue was declared
    [],        // arguments: extra options (e.g. x-message-ttl, x-dead-letter-exchange, x-max-length)
    null       // ticket: legacy access ticket, unused in modern RabbitMQ (always null)
);

// Prefetch = 1: RabbitMQ sends this worker only ONE unacknowledged message at a time.
$channel->basic_qos(0, 1, false);

$processOrder = function (AMQPMessage $message): void {
    $order = json_decode($message->getBody(), true);

    if (!isset($order['order_id'])) {
        // A broken message would fail forever, so drop it instead of requeueing.
        logLine('Invalid message, rejecting: ' . $message->getBody());
        $message->reject(false);
        return;
    }

    $orderId = $order['order_id'];
    logLine("📥 Received $orderId");

    // Pretend this is slow work (charging a card, sending an email, ...)
    sleep(3);

    $line = sprintf(
        "%s | created %s | processed %s | by %s\n",
        $orderId,
        $order['created_at'] ?? '?',
        date(DATE_ATOM),
        WORKER_NAME
    );

    if (@file_put_contents(OUTPUT_FILE, $line, FILE_APPEND | LOCK_EX) === false) {
        // No ACK! When this process exits, RabbitMQ puts the message back in the queue.
        logLine("❌ Could not write $orderId to orders.txt — exiting WITHOUT ack");
        exit(1);
    }
    logLine("📝 Wrote $orderId to orders.txt");

    // Wait 5 seconds before the ACK, so you can see the message as "Unacked" in the dashboard.
    logLine("⏳ Waiting 5 seconds before ACK for $orderId");
    sleep(5);

    // Manual ACK: tells RabbitMQ "done, you can delete this message".
    $message->ack();
    logLine("✅ ACK sent to RabbitMQ for $orderId");
};

// Arguments: queue, consumer_tag, no_local, no_ack (false = manual ack), exclusive, nowait, callback
$channel->basic_consume('orders', '', false, false, false, false, $processOrder);

logLine('👷 Worker started. Waiting for orders...');

while ($channel->is_consuming()) {
    $channel->wait();
}
