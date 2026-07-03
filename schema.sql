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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
