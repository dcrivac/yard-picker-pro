-- Yard Picker Pro schema for Cloudflare D1 (SQLite). Same tables and columns
-- as the MySQL schema.sql, plus tables that replace PHP's temp files and
-- sessions. Times are UTC text 'YYYY-MM-DD HH:MM:SS'; *_at INTEGER columns
-- are unix seconds.

CREATE TABLE IF NOT EXISTS users (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    email             TEXT NOT NULL UNIQUE,
    password_hash     TEXT NOT NULL,              -- pbkdf2_sha256$... or a legacy $2y$ bcrypt hash
    credits           INTEGER NOT NULL DEFAULT 0, -- purchased, never-expiring credits
    free_credits_used INTEGER NOT NULL DEFAULT 0,
    free_period_start TEXT NOT NULL,              -- 'YYYY-MM-DD'
    created_at        TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS credit_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    delta      INTEGER NOT NULL,
    reason     TEXT NOT NULL,
    ref        TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (ref, reason)                          -- guards against double-crediting on webhook retries
);
CREATE INDEX IF NOT EXISTS idx_credit_events_user ON credit_events(user_id);

CREATE TABLE IF NOT EXISTS password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL,                     -- sha256(raw token), hex
    expires_at TEXT NOT NULL,
    used_at    TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_password_resets_token ON password_resets(token_hash);

CREATE TABLE IF NOT EXISTS tracker_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    part       TEXT NOT NULL,
    car        TEXT NOT NULL,
    lkq_cost   REAL NULL,
    labor_cost REAL NULL,
    ebay_avg   REAL NULL,
    list_price REAL NULL,
    sale_price REAL NULL,
    reason     TEXT NULL,
    status     TEXT NOT NULL DEFAULT 'pulled',
    pulled_at  TEXT NULL,
    listed_at  TEXT NULL,
    sold_at    TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_tracker_items_user ON tracker_items(user_id);

-- Login sessions (replaces PHP sessions). Only sha256(token) is stored.
CREATE TABLE IF NOT EXISTS sessions (
    token_hash TEXT PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);

-- Fixed-window rate-limit counters (replaces the yp-rl-* temp files).
CREATE TABLE IF NOT EXISTS rate_hits (
    bucket     TEXT PRIMARY KEY,
    count      INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);

-- General cache: eBay token and results, yard prices, vPIC (replaces temp files).
CREATE TABLE IF NOT EXISTS cache (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    expires_at INTEGER NOT NULL
);

-- Anthropic spend per UTC day, for the daily cost ceiling.
CREATE TABLE IF NOT EXISTS daily_cost (
    day TEXT PRIMARY KEY,
    usd REAL NOT NULL
);
