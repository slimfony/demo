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
