# scafera/log

Structured logging for the Scafera framework. Implements PSR-3 with a zero-dependency `StreamLogger` that writes JSON Lines to `var/log/{environment}.log`.

## Core Idea

Scafera treats logging the same way it treats every other capability — explicit, minimal, and boundary-safe. Application log calls are written by the developer at the call site — no userland listeners, middleware, or automatic logging. Framework-level errors (uncaught exceptions, console failures) are captured automatically by the package as infrastructure. The logger writes structured JSON Lines with a required `event` field for categorization — required by build-time validation (`scafera validate`), not enforced at runtime.

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

The `event` key is extracted from the context array and promoted to a top-level JSON field — it does not appear inside `context`. The remaining context is written under `context`, which is omitted entirely when empty after extraction. This makes log entries greppable and filterable by the CLI commands.

### Log Format

Each line is a self-contained JSON object:

```json
{"timestamp":"2026-04-10T16:22:40.501+00:00","level":"info","message":"Order placed","event":"order.created","context":{"orderId":1}}
```

Fields: `timestamp` (RFC3339 with milliseconds), `level` (PSR-3), `message`, `event` (present only when the context includes an `event` key), `context` (remaining context after `event` extraction, omitted if empty).

### Context Serialization

The context array is sanitized to produce valid JSON. Scalars and arrays pass through, `\Throwable` instances (under the `exception` key per PSR-3 convention) are expanded to structured data, and non-serializable values are reduced to a safe representation. The exact serialization rules are an implementation detail and may evolve, but the output is always valid JSON.

Example of `\Throwable` serialization:

```php
$this->logger->error('Payment failed', [
    'event' => 'payment.failed',
    'exception' => $e,
]);
```

```json
{"timestamp":"...","level":"error","message":"Payment failed","event":"payment.failed","context":{"exception":{"class":"App\\Exception\\PaymentFailedException","message":"Card declined","code":0,"file":"/app/src/Service/PaymentService.php","line":42}}}
```

### Failure Behavior

Logging failures throw `\RuntimeException`. If the log directory doesn't exist or permissions are wrong, the error is visible immediately — no silent failures. This is a conscious trade-off: a failed log call will break the request flow, because the developer wrote the call and intended the entry to exist. Silent failure would mean the developer believes logging is working when it isn't.

The logger does **not** validate the `event` key at runtime. It writes whatever context it receives. Structured logging (consistent `event` key, lowercase dot notation) is guaranteed only when `EventContextValidator` is run via `scafera validate`.

## Framework Error Logging

When `scafera/log` is installed, uncaught exceptions are automatically logged to the same log file alongside application entries. This is framework infrastructure — the log package takes ownership of error visibility.

**HTTP exceptions** are logged with event `framework.http.error`:
- 4xx client errors (404, 403, etc.) are logged at `warning` level — these are client mistakes, not system failures
- 5xx server errors and unhandled exceptions are logged at `error` level

```json
{"timestamp":"...","level":"warning","message":"No route found for \"GET /nonexistent\"","event":"framework.http.error","context":{"exception":{...},"method":"GET","path":"/nonexistent","status":404}}
```

**Console exceptions** are logged with event `framework.console.error` at `error` level, including the command name and exit code.

The CLI commands work naturally with framework entries — `logs:errors` shows framework 500s, `logs:filter framework.http.error` isolates framework entries, and `logs:stats` counts them as a distinct event.

Symfony's built-in error logging is disabled via a compiler pass to prevent duplicate entries. Symfony's error response handling (the error page in dev, the error controller in prod) continues to work normally.

## Build-Time Validation

The `EventContextValidator` runs during `scafera validate` and checks:

- Every logger call in `src/` includes an `'event' =>` key in the context
- Event values match the format `domain.action` (lowercase dot notation: `/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/`)

The validator uses token-based scanning (not an AST parser). It detects inline logger calls but cannot detect context built via variables, method returns, or array spread.

## CLI Commands

All commands are available via `vendor/bin/scafera`:

```bash
# Operational summary — errors/warnings from the last 24 hours (by timestamp), top events, recent failures
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

# Combine filters (AND logic — all conditions must match)
vendor/bin/scafera logs:filter --level=error --search="failed"

# Limit results (default 50 for both logs:filter and logs:errors)
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

While it is possible to override the logger by aliasing `Psr\Log\LoggerInterface` to your own class, this is **not recommended**. Scafera packages are designed to work together — `StreamLogger` produces the JSON Lines format that the CLI commands (`logs:stats`, `logs:filter`, `logs:errors`, `logs:status`) depend on. A custom implementation that changes the output format will break CLI compatibility. Prefer staying with `StreamLogger` unless you have a specific need that it cannot satisfy.

```yaml
# Not recommended — only if StreamLogger truly cannot meet your needs
services:
    Psr\Log\LoggerInterface:
        alias: App\CustomLogger
```

If you must override, your implementation should write JSON Lines to `var/log/{environment}.log` with the same field structure (`timestamp`, `level`, `message`, `event`, `context`) to maintain CLI compatibility.

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
