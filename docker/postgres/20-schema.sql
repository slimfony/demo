-- Uploaded ZIP files. The ZIP itself is kept in the row (a few KB each), so the importers need no shared file system.
CREATE TABLE batches (
    id          uuid        PRIMARY KEY,
    sha256      text        NOT NULL,
    mode        text        NOT NULL CHECK (mode IN ('partial', 'strict')),
    status      text        NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
    source      text        NOT NULL CHECK (source IN ('jwt', 'public')),
    lock_order  text        NOT NULL DEFAULT 'sorted' CHECK (lock_order IN ('sorted', 'appearance')),
    subject     text,
    token_kid   text,
    size_bytes  integer     NOT NULL,
    entries     integer     NOT NULL,
    payload     bytea       NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    -- filled by the importer
    worker             text,
    started_at         timestamptz,
    finished_at        timestamptz,
    reannounced_at     timestamptz, -- when the sweeper last announced this batch again
    statements_ok      integer NOT NULL DEFAULT 0,
    statements_failed  integer NOT NULL DEFAULT 0,
    statements_rolled_back integer NOT NULL DEFAULT 0,
    entries_booked     integer NOT NULL DEFAULT 0
);
CREATE INDEX batches_created_at ON batches (created_at);

-- Chaos switches of the demo: a row is a request to break something (see Chaos\KillTokens).
CREATE TABLE chaos (
    id         bigserial   PRIMARY KEY,
    action     text        NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

-- Written in the same transaction as the batch; a relay publishes the rows to NATS.
CREATE TABLE outbox (
    id           bigserial   PRIMARY KEY,
    topic        text        NOT NULL,
    payload      jsonb       NOT NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),
    published_at timestamptz
);
CREATE INDEX outbox_unpublished ON outbox (id) WHERE published_at IS NULL;

-- A run of the page's import. The server generates and uploads the ZIPs (the `generator` service), so a reload of the page
-- neither stops the run nor loses it: the page follows it from these tables and from `batches`.
CREATE TABLE import_runs (
    id          uuid        PRIMARY KEY,
    zips        integer     NOT NULL CHECK (zips BETWEEN 1 AND 1000),
    fault_rate  numeric     NOT NULL CHECK (fault_rate BETWEEN 0 AND 1),
    mode        text        NOT NULL CHECK (mode IN ('partial', 'strict')),
    accounts    integer     NOT NULL CHECK (accounts BETWEEN 1 AND 500),
    lock_order  text        NOT NULL CHECK (lock_order IN ('sorted', 'appearance')),
    status      text        NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'uploading', 'uploaded')),
    created_at  timestamptz NOT NULL DEFAULT now(),
    uploaded_at timestamptz
);

-- What became of every ZIP of a run at the door: the batch it created, or the refusal.
CREATE TABLE import_run_items (
    run_id      uuid    NOT NULL REFERENCES import_runs (id) ON DELETE CASCADE,
    idx         integer NOT NULL,
    batch_id    uuid,
    via         text    NOT NULL CHECK (via IN ('jwt', 'public')),
    http_status integer NOT NULL,
    PRIMARY KEY (run_id, idx)
);

-- A run of the page's "Try it": requests to one endpoint, sent by the generator service (so a reload of the page does not stop it).
CREATE TABLE load_runs (
    id          uuid        PRIMARY KEY,
    requests    integer     NOT NULL CHECK (requests BETWEEN 1 AND 200000),
    endpoint    text        NOT NULL CHECK (endpoint IN ('/healthz', '/api/runtime')),
    status      text        NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done')),
    sent        integer     NOT NULL DEFAULT 0,
    errors      integer     NOT NULL DEFAULT 0,
    seconds     numeric,
    p50_ms      numeric,
    p95_ms      numeric,
    p99_ms      numeric,
    created_at  timestamptz NOT NULL DEFAULT now(),
    finished_at timestamptz
);
