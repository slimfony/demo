-- The accounts a statement may refer to. Few on purpose: every statement updates them, so concurrent importers meet on the same rows.
CREATE TABLE accounts (
    iban             text           PRIMARY KEY,
    currency         char(3)        NOT NULL,
    booked_balance   numeric(18, 2) NOT NULL DEFAULT 0,
    last_statement_at timestamptz
);

-- One hot row per account, touched by every entry that names the account: the place where deadlocks come from.
CREATE TABLE account_stats (
    iban              text   PRIMARY KEY REFERENCES accounts (iban),
    entries_total     bigint NOT NULL DEFAULT 0,
    last_booking_date date
);

CREATE TABLE statements (
    id              bigserial      PRIMARY KEY,
    batch_id        uuid           NOT NULL REFERENCES batches (id),
    statement_id    text           NOT NULL UNIQUE,
    iban            text           NOT NULL REFERENCES accounts (iban),
    currency        char(3)        NOT NULL,
    period_from     timestamptz    NOT NULL,
    period_to       timestamptz    NOT NULL,
    opening_balance numeric(18, 2) NOT NULL,
    closing_balance numeric(18, 2) NOT NULL,
    created_at      timestamptz    NOT NULL DEFAULT now()
);
CREATE INDEX statements_batch ON statements (batch_id);

CREATE TABLE entries (
    id               bigserial      PRIMARY KEY,
    statement_pk     bigint         NOT NULL REFERENCES statements (id) ON DELETE CASCADE,
    entry_ref        text           NOT NULL,
    booking_date     date           NOT NULL,
    amount           numeric(18, 2) NOT NULL CHECK (amount > 0),
    cdt_dbt          char(4)        NOT NULL CHECK (cdt_dbt IN ('CRDT', 'DBIT')),
    counterparty     text,
    remittance_info  text,
    UNIQUE (statement_pk, entry_ref)
);

-- Rejected statements: written outside the statement's own transaction, so the row survives its rollback.
CREATE TABLE import_errors (
    id           bigserial   PRIMARY KEY,
    batch_id     uuid        NOT NULL REFERENCES batches (id),
    file         text        NOT NULL,
    statement_id text,
    reason       text        NOT NULL,
    detail       text,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX import_errors_batch ON import_errors (batch_id);

INSERT INTO accounts (iban, currency, booked_balance)
SELECT 'HU' || lpad(i::text, 26, '0'), 'EUR', 100000.00 FROM generate_series(1, 12) AS i;
INSERT INTO account_stats (iban) SELECT iban FROM accounts;
