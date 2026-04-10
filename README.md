# scafera/log

Structured logging for the Scafera framework. Implements PSR-3 with a zero-dependency `StreamLogger` that writes JSON Lines to `var/log/{environment}.log`.

## Core Idea

Scafera treats logging the same way it treats every other capability — explicit, minimal, and boundary-safe. Every log call is written by the developer at the call site. There are no listeners, middleware, exception handlers, or automatic logging. The logger writes structured JSON Lines with an optional `event` field for categorization, enforced at build time via `scafera validate`.

## Installation

```bash
composer require scafera/log
```

The bundle is auto-discovered via Scafera's `symfony-bundle` type detection. No manual registration needed.

## Requirements

- PHP 8.4+
- `scafera/kernel` ^1.0
- `psr/log` ^3.0

## Usage

Inject `Psr\Log\LoggerInterface` via constructor — it resolves to `StreamLogger` automatically:

```php
use Psr\Log\LoggerInterface;

final class OrderService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function place(Order $order): void
    {
        // ... business logic ...

        $this->logger->info('Order placed', [
            'event' => 'order.created',
            'orderId' => $order->getId(),
        ]);
    }
}
```

### Event Field Convention

Every log call should include an `event` key in the context array. Events use lowercase dot notation (`domain.action`):

```php
$this->logger->info('User signed in', ['event' => 'auth.login', 'userId' => 42]);
$this->logger->error('Payment failed', ['event' => 'payment.failed', 'orderId' => 1]);
$this->logger->warning('Slow query', ['event' => 'db.slow_query', 'duration_ms' => 1250]);
```

The event field is promoted to the top level of the JSON entry — it is not nested inside `context`. This makes log entries greppable and filterable by the CLI commands.

### Log Format

Each line is a self-contained JSON object:

```json
{"timestamp":"2026-04-10T16:22:40.501+00:00","level":"info","message":"Order placed","event":"order.created","context":{"orderId":1}}
```

Fields: `timestamp` (RFC3339 with milliseconds), `level` (PSR-3), `message`, `event` (if provided), `context` (remaining context, omitted if empty).

### Context Serialization

The logger handles context values as follows:

| Type | Serialization |
|------|---------------|
| Scalar, null | As-is |
| `Throwable` (under `exception` key) | `{class, message, code, file, line}` |
| `Stringable` | Cast to string |
| Array | Recursively sanitized |
| Object | Fully qualified class name |
| Other | `[unsupported type]` |

### Failure Behavior

Logging failures throw `\RuntimeException`. If the log directory doesn't exist or permissions are wrong, the error is visible immediately — no silent failures.

The logger does **not** validate the `event` key at runtime. It writes whatever context it receives. Structured logging (consistent `event` key, lowercase dot notation) is guaranteed only when `EventContextValidator` is run via `scafera validate`.

## Build-Time Validation

The `EventContextValidator` runs during `scafera validate` and checks:

- Every logger call in `src/` includes an `'event' =>` key in the context
- Event values match the format `domain.action` (lowercase dot notation: `/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/`)

The validator uses token-based scanning (not an AST parser). It detects inline logger calls but cannot detect context built via variables, method returns, or array spread.

## CLI Commands

All commands are available via `vendor/bin/scafera`:

```bash
# Operational summary — errors/warnings in last 24h, top events, recent failures
vendor/bin/scafera logs:status

# Show entries with severity >= error (error, critical, alert, emergency)
vendor/bin/scafera logs:errors

# Aggregate counts grouped by event, with level column
vendor/bin/scafera logs:stats

# Group by event and level separately
vendor/bin/scafera logs:stats --by-level

# Filter by event name
vendor/bin/scafera logs:filter order.created

# Filter by level
vendor/bin/scafera logs:filter --level=warning

# Search text in messages or context
vendor/bin/scafera logs:filter --search="timeout"

# Combine filters
vendor/bin/scafera logs:filter --level=error --search="failed"

# Limit results (default 50)
vendor/bin/scafera logs:errors --limit=10

# JSON output (all commands)
vendor/bin/scafera logs:status --json
vendor/bin/scafera logs:errors --json
vendor/bin/scafera logs:stats --json
vendor/bin/scafera logs:filter order.created --json
```

### JSON Output

All commands support `--json` for machine-readable output. The format uses a `meta` + `data` envelope:

```json
{
    "meta": {"command": "logs:stats", "env": "dev", "file": "/app/var/log/dev.log"},
    "data": [...]
}
```

## Configuration

None. The bundle registers `StreamLogger` with `%kernel.logs_dir%` and `%kernel.environment%` — one logger, one file per environment. No config keys, no extension points.

To override the logger implementation, alias `Psr\Log\LoggerInterface` to your own class in `config/config.yaml`:

```yaml
services:
    Psr\Log\LoggerInterface:
        alias: App\CustomLogger
```

## Testing

```bash
# From the consumer project (e.g., milestone3)
docker compose exec php vendor/bin/phpunit \
  -c vendor/scafera/log/tests/phpunit.xml \
  --bootstrap vendor/autoload.php \
  --testdox
```

## Roadmap

### Monolog Adapter

A built-in adapter for `monolog/monolog` for applications needing log routing, multiple outputs, or advanced formatting. Deferred — `StreamLogger` covers the common case, and applications can wire Monolog manually via the `LoggerInterface` alias override.
