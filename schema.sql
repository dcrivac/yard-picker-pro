-- Yard Picker Pro schema. Run once against the MySQL database created in
-- SiteGround Site Tools > MySQL. In phpMyAdmin: select the database, open the
-- SQL tab, paste this, run.

CREATE TABLE IF NOT EXISTS users (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    email             VARCHAR(255) NOT NULL UNIQUE,
    password_hash     VARCHAR(255) NOT NULL,
    credits           INT NOT NULL DEFAULT 0,          -- purchased, never-expiring credits
    free_credits_used INT NOT NULL DEFAULT 0,          -- free-tier analyses used this period
    free_period_start DATE NOT NULL,                   -- start of the current monthly free window
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ledger of credit changes (purchases, spends, grants) for auditing + support.
CREATE TABLE IF NOT EXISTS credit_events (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    delta      INT NOT NULL,                           -- + for grant/purchase, - for spend
    reason     VARCHAR(64) NOT NULL,                   -- 'purchase', 'analysis', 'signup_grant', etc.
    ref        VARCHAR(128) NULL,                      -- e.g. Stripe session id
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    UNIQUE KEY uniq_ref_reason (ref, reason),             -- guards against double-crediting on webhook retries
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Password reset tokens. Raw token is emailed to the user and never stored;
-- only its SHA-256 hash lives here, same principle as password hashing.
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token_hash CHAR(64) NOT NULL,                       -- sha256(raw token), hex
    expires_at TIMESTAMP NOT NULL,
    used_at    TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (token_hash),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Profit Tracker items, tied to the user's account instead of localStorage.
-- id is server-assigned (the client no longer generates ids) so every
-- lookup/update/delete can be safely scoped to (id, user_id).
CREATE TABLE IF NOT EXISTS tracker_items (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    part       VARCHAR(255) NOT NULL,
    car        VARCHAR(255) NOT NULL,
    lkq_cost   DECIMAL(10,2) NULL,
    labor_cost DECIMAL(10,2) NULL,
    ebay_avg   DECIMAL(10,2) NULL,
    list_price DECIMAL(10,2) NULL,
    sale_price DECIMAL(10,2) NULL,
    reason     VARCHAR(255) NULL,
    status     VARCHAR(16) NOT NULL DEFAULT 'pulled',    -- 'pulled' / 'listed' / 'sold'
    pulled_at  TIMESTAMP NULL,
    listed_at  TIMESTAMP NULL,
    sold_at    TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
