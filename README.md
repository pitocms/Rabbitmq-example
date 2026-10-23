# RabbitMQ Tutorial: Plain PHP + Docker Compose

A small order system: a web page **publishes** orders to RabbitMQ, and a background worker **consumes** them.
There's no framework, no database and no email server.

## The 3 containers

| Container   | Role                | What it does |
|-------------|---------------------|--------------|
| `order-app` | Producer (web, PHP) | Shows a "Place Order" button at http://localhost:8080. Each click creates an order ID such as `ORD-A7K2` and publishes a JSON message to the `orders` queue. |
| `rabbitmq`  | Message broker      | Stores the messages in the durable `orders` queue until a worker takes them. Includes the management dashboard. |
| `worker-1`, `worker-2` | Consumers (CLI, PHP) | Two copies of the same worker, both reading the `orders` queue. Each runs forever and takes one message at a time. It waits 3 seconds (fake work), appends a line to `output/orders.txt` (including which worker handled it), waits 5 more seconds, then sends an ACK. RabbitMQ gives each order to only **one** of the workers. |

## Message flow

```
 Browser ──POST──▶ order-app ──publish──▶ RabbitMQ [orders queue] ──deliver──▶ worker
    ▲                  │       ◀─confirm──                           ◀──ACK────    │
    └── "Order queued" ┘                                                          ▼
                                                                       output/orders.txt
```

1. You click **Place Order**. The browser sends a POST to `order-app`.
2. `order-app` publishes `{"order_id": "ORD-A7K2", "created_at": "..."}` as a **persistent** message.
3. RabbitMQ sends back a **publisher confirm** ("I've got it"). Only then does the page say **Order queued**.
   If publishing fails, the page shows an error instead.
4. The page responds right away. It does **not** wait for the worker.
5. RabbitMQ delivers the message to the `worker`. **Prefetch = 1** means a worker holds at most one unacknowledged message at a time.
6. The worker sleeps 3 seconds, then appends a line to `orders.txt`.
7. After the write succeeds, the worker waits 5 seconds (so you can see the message as **Unacked** in the dashboard), then sends an **ACK**. The ACK travels **from the worker back to RabbitMQ**,
   and it tells RabbitMQ: "Done, you can delete this message."

If the write fails, the worker logs the error and exits **without an ACK**. RabbitMQ sees the connection close and
puts the message back in the queue. Docker restarts the worker (`restart: on-failure`), and the worker tries again.

> **Duplicates are possible.** Suppose the worker crashes *after* writing to `orders.txt` but *before* the ACK
> reaches RabbitMQ. RabbitMQ never got the ACK, so it delivers the message again, and the same order is written twice.
> RabbitMQ guarantees "at least once" delivery, not "exactly once". Real systems make the work *idempotent*,
> for example by skipping order IDs they have already processed.

### Two separate confirmations

- **Publisher confirm** (RabbitMQ → order-app): "The message is safely in the queue." The page shows this as *Order queued*.
- **Consumer ACK** (worker → RabbitMQ): "The message has been processed." You see this in the worker logs and `orders.txt`.

The web page only knows about the first one. That's why it shows "Queued" and "Processing" as separate boxes.

## Settings worth pointing out

| Setting                                | Where              | Why |
|----------------------------------------|--------------------|-----|
| Durable queue                          | `queue_declare(..., durable: true)` | The queue survives a RabbitMQ restart. |
| Persistent messages                    | `delivery_mode = 2` | Messages are written to disk. |
| Named volume `rabbitmq-data`           | `docker-compose.yml` | RabbitMQ's disk data survives `docker compose down`. |
| `hostname: rabbitmq`                   | `docker-compose.yml` | RabbitMQ stores data per node name, so a fixed hostname lets it find its data again. |
| Health check + `service_healthy`       | `docker-compose.yml` | The PHP containers start only after RabbitMQ accepts connections. |
| Post/Redirect/Get                      | `order-app/index.php` | After a POST, the app redirects to a GET page. Refreshing won't place another order. |
| Hostname `rabbitmq`                    | `RABBITMQ_HOST` env | Inside Docker, containers reach each other by service name. |
| `127.0.0.1:` port bindings             | `docker-compose.yml` | The web app and dashboard are reachable only from your machine. |

## Commands

```bash
# Start everything (first run builds the PHP image and installs php-amqplib via Composer)
docker compose up --build

# ...or start it in the background
docker compose up --build -d

# Stop everything (keeps RabbitMQ data in the volume)
docker compose down

# Stop everything AND delete RabbitMQ data
docker compose down -v

# Logs
docker compose logs -f              # all containers
docker compose logs -f worker-1 worker-2   # just the workers
docker compose logs -f order-app

# View processed orders
cat output/orders.txt
tail -f output/orders.txt           # watch new lines arrive

# Queue status from the command line
docker compose exec rabbitmq rabbitmqctl list_queues name messages_ready messages_unacknowledged consumers

# Clear orders.txt before recording
: > output/orders.txt
```

## RabbitMQ management dashboard

- URL: **http://localhost:15672**
- Username: **tutorial**
- Password: **tutorial123**

To change the login, create a `.env` file next to `docker-compose.yml`:

```
RABBITMQ_USER=myuser
RABBITMQ_PASS=mypassword
```

RabbitMQ only creates the user the **first time** it starts with an empty volume.
After you change the login, run `docker compose down -v` once.

## Live demo sequence

Open three windows: a terminal, the web app (http://localhost:8080) and the dashboard (http://localhost:15672, then **Queues and Streams → orders**).

1. **Start the project**
   ```bash
   docker compose up --build -d
   docker compose logs -f worker-1 worker-2   # leave this running in a second terminal
   ```
   Each worker prints `[worker-1] Worker started. Waiting for orders...` (or `[worker-2]`).

2. **Stop the workers**
   ```bash
   docker compose stop worker-1 worker-2
   ```
   In the dashboard, the `orders` queue now shows **0 consumers**.

3. **Submit 3 orders.** Click **Place Order** three times. Each click shows *Order queued* and a new ID right away,
   even though nobody is processing the orders.

4. **Show the waiting messages.** The dashboard shows **Ready: 3**. You can also check from the terminal:
   ```bash
   docker compose exec rabbitmq rabbitmqctl list_queues name messages_ready messages_unacknowledged consumers
   ```
   `output/orders.txt` hasn't changed.

5. **Start the workers**
   ```bash
   docker compose start worker-1 worker-2
   docker compose logs -f worker-1 worker-2
   ```

6. **Watch the queue drain.** The two workers share the orders: each one logs `Received → Wrote → Waiting 5 seconds → ACK sent`
   for a different order, and they work at the same time. In the dashboard, **Consumers** shows **2**,
   and **Unacked** goes up to 2 (one per worker, because prefetch = 1).
   In another terminal, run `tail -f output/orders.txt` to watch the new lines arrive. The `by worker-1` / `by worker-2`
   at the end of each line shows who processed it.

## Files

```
docker-compose.yml     # rabbitmq, order-app, worker-1, worker-2, health check, volume, ports
Dockerfile             # PHP 8.3 CLI + sockets extension + Composer install of php-amqplib
composer.json          # php-amqplib/php-amqplib ^3.7
order-app/index.php    # producer: web page + publish with publisher confirms
worker/worker.php      # consumer: prefetch 1, 3 s delay, write file, 5 s delay, manual ACK
output/                # orders.txt is written here (mounted into both workers)
```

Both PHP containers use the same image and run different commands.
