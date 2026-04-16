# scafera/log

Structured logging for the Scafera framework. Implements PSR-3 with a zero-dependency `StreamLogger` that writes JSON Lines to `var/log/{environment}.log`.

> **Provides:** Structured logging (PSR-3) for Scafera — a zero-dependency `StreamLogger` that writes JSON Lines to `var/log/{env}.log`. Uncaught framework exceptions are auto-logged; application code writes its own entries.
>
> **Depends on:** A Scafera host project (kernel + architecture package) with a writable `var/log/` directory on reliable local storage. Application code injects `Psr\Log\LoggerInterface` — not `StreamLogger` directly.
>
> **Extension points:** None by design — `StreamLogger` is not extensible. You can alias `Psr\Log\LoggerInterface` to a custom implementation, but doing so breaks the `logs:*` CLI commands, which depend on the JSON Lines format.
>
> **Not responsible for:** Log rotation (delegated to `logrotate`/OS) · best-effort logging (`RuntimeException` on write failure, per ADR-049) · application log routing or multi-destination (use Monolog manually) · runtime validation of the `event` key (build-time only via `EventContextValidator`) · Symfony's default error logging (disabled via compiler pass to avoid duplicate entries).

This is a **capability package**. It adds optional structured logging to a Scafera project. It does not define folder structure or architectural rules — those belong to architecture packages.

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
{"timestamp":"2026-04-10T16:22:40.501+00:00","level":"info","message":"Order placed","event":"order.created","ip":"192.168.1.42","context":{"orderId":1}}
```

Fields: `timestamp` (RFC3339 with milliseconds), `level` (PSR-3), `message`, `event` (present only when the context includes an `event` key), `ip` (client IP address, present only during HTTP requests), `context` (remaining context after `event` extraction, omitted if empty). Client IP is logged automatically during HTTP requests. To disable, override the `StreamLogger` service definition and omit the `RequestStack` argument.

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

Logging failures throw `\RuntimeException`. If the log directory doesn't exist, permissions are wrong, or the underlying disk cannot accept the write, the error is visible immediately — no silent failures, no fallback channel. The underlying OS error message is captured via a temporary error handler and folded into the exception message, so the exception text includes the real reason (e.g. `No space left on device`, `Permission denied`).

This is a conscious trade-off, documented in [ADR-049](../../docs/ADR/decisions.md#adr-049-logging-is-a-failure-surface-fail-loud-io): Scafera treats logging as part of the system's feedback loop, not a best-effort side channel. A successful request that was not logged is *unobservable*, and in Scafera's model an unobservable success is worse than a visible failure. Projects that need best-effort logging (log loss acceptable to preserve availability) should use a different PSR-3 implementation.

The logger does **not** validate the `event` key at runtime. It writes whatever context it receives. Structured logging (consistent `event` key, lowercase dot notation) is guaranteed only when `EventContextValidator` is run via `scafera validate`.

### Storage and Rotation

Because write failures propagate as exceptions, storage choice matters:

- **Logs must be written to reliable local storage.** Local disk, local tmpfs, or a mounted volume backed by local block storage.
- **NFS and other network filesystems are not recommended as a log sink.** Network storage introduces failure modes (remote unavailability, soft-mount timeouts, partial writes) that will surface as `RuntimeException` and affect request handling. If you mount `var/log/` over NFS, you accept that transient network issues will cause request failures.
- **Rotation is delegated to the operating system.** `scafera/log` does not implement in-process rotation — the hot write path is intentionally simple. Use `logrotate` (or an equivalent) configured to rotate on size or date. `StreamLogger` opens the log file fresh on every write via `file_put_contents`, so post-rotation writes go to the new file without needing a reload signal.

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

The validator uses PHP's tokenizer (`\PhpToken::tokenize`, not an AST parser). It handles single-line and multi-line logger calls, inspects only the top level of the context array (nested `'event'` keys are ignored), and format-checks event values only when they are string literals — dynamic values like `Event::CREATED` are accepted as present. Context built via a variable (`$logger->info($msg, $context)`), method return, or array spread cannot be inspected and is silently skipped.

## CLI Commands

All commands are available via `vendor/bin/scafera`:

```bash
# Show latest log entries (default 50)
vendor/bin/scafera logs:recent

# Show latest 10 entries
vendor/bin/scafera logs:recent --limit=10

# Show latest entries filtered by level
vendor/bin/scafera logs:recent --level=error

# Show only application logs (excludes framework.* events)
vendor/bin/scafera logs:recent --scope=app

# Show only framework logs
vendor/bin/scafera logs:recent --scope=framework

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

# Filter by scope
vendor/bin/scafera logs:filter --scope=app
vendor/bin/scafera logs:filter --scope=framework

# Combine filters (AND logic — all conditions must match)
vendor/bin/scafera logs:filter --level=error --scope=app

# Limit results (default 50 for both logs:filter and logs:errors)
vendor/bin/scafera logs:errors --limit=10

# Clear the log file
vendor/bin/scafera logs:clear

# JSON output (all commands)
vendor/bin/scafera logs:status --json
vendor/bin/scafera logs:errors --json
vendor/bin/scafera logs:stats --json
vendor/bin/scafera logs:recent --json
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

## Roadmap

### Monolog Adapter

A built-in adapter for `monolog/monolog` for applications needing log routing, multiple outputs, or advanced formatting. Deferred — `StreamLogger` covers the common case, and applications can wire Monolog manually via the `LoggerInterface` alias override.

## License

MIT
