# `gplanchat/durable`

PHP library for **durable execution**: workflows, activities, append-only **event journal**, **replay**, and transports. HTTP / Symfony integration lives in **`gplanchat/durable-bundle`**.

> **Read-only mirror.** This repository is a subtree-split of
> **[gplanchat/durable-dev](https://github.com/gplanchat/durable-dev)**, published so Composer can
> require this package on its own. Issues and pull requests are disabled here — open them **[on the
> monorepo](https://github.com/gplanchat/durable-dev/issues)**.
>
> **The tests are in the monorepo, not here.** This split carries source only. What covers it is
> `tests/unit/Durable/` in the monorepo, run by its `unit` suite.
>
> **Documentation**: [durable.rocks](https://durable.rocks).

## Documentation

- **User guide**: [durable.rocks/docs](https://durable.rocks/docs/) (published via Hugo; see [`documentation/HUGO.md`](https://github.com/gplanchat/durable-dev/blob/main/documentation/HUGO.md))
- **Architecture (contributors)**: [`documentation/INDEX.md`](https://github.com/gplanchat/durable-dev/blob/main/documentation/INDEX.md) — decisions prefixed **DUR** under `documentation/adr/`

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

## License

**MIT** — see [`LICENSE`](LICENSE) in this directory and [WA004](https://github.com/gplanchat/durable-dev/blob/main/documentation/wa/WA004-mit-license-distribution.md).
