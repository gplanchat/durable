# `gplanchat/durable`

PHP library for **durable execution**: workflows, activities, append-only **event journal**, **replay**, and transports. HTTP / Symfony integration lives in **`gplanchat/durable-bundle`**.

## Documentation

- **User guide**: repository root [`documentation/user/`](../../documentation/user/) (published via Hugo; see [`documentation/HUGO.md`](../../documentation/HUGO.md))
- **Architecture (contributors)**: [`documentation/INDEX.md`](../../documentation/INDEX.md) — decisions prefixed **DUR** under `documentation/adr/`

## Highlights

- **DUR003** — Fiber-based replay, execution engine, awaitables
- **DUR004** — Activity stubs and activity methods
- **DUR005** — Temporal and in-memory backends
- **DUR007** — Serialization (Symfony Serializer where applicable)

## Optional static analysis

Composer `suggest` lists optional PHPStan / Psalm extensions (see **DUR012** in the ADR index).

## Install

```bash
composer require gplanchat/durable
```

Use **`gplanchat/durable-bundle`** for Symfony applications.
