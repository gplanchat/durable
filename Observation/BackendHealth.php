<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Is the backend answering, right now.
 *
 * Distinct from "a backend is configured": a registered catalog whose database has gone down would
 * otherwise show an empty, serene dashboard, which is the worse of the two possible errors — the
 * operator concludes there is nothing to see.
 *
 * `backend` names what was probed — "SQL database", "Temporal" — because an operator who reads
 * "unreachable" needs to know what to go and switch back on. It is the exact opposite of the case
 * where *no* backend is configured: there, naming a server that was never part of the picture
 * would send them down a false trail.
 *
 * `ephemeral` is the **third** state, and it follows from neither of the other two: the backend
 * answers, and its answer is empty because its journal does not survive the process that writes it.
 * Under PHP-FPM, the request that renders the dashboard has never run a single workflow. Filed
 * under "reachable", this case teaches the operator that no workflow has ever run, which is false;
 * filed under "unreachable", it sends them to switch back on a server that does not exist.
 *
 * The default is `false`: the three catalogs that write outside the process — SQL, Illuminate,
 * Temporal — have no need to declare what is true of them by construction.
 */
final readonly class BackendHealth
{
    public function __construct(
        public string $backend,
        public bool $reachable,
        public string $message,
        public \DateTimeImmutable $checkedAt,
        public bool $ephemeral = false,
    ) {}
}
