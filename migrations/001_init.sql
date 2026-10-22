CREATE TABLE settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    wage_cents INTEGER NOT NULL DEFAULT 0,
    currency_symbol TEXT NOT NULL DEFAULT 'Kč',
    updated_at TEXT NOT NULL
);
INSERT INTO settings (id, wage_cents, currency_symbol, updated_at) VALUES (1, 0, 'Kč', strftime('%Y-%m-%dT%H:%M:%SZ', 'now'));

CREATE TABLE shifts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    break_minutes INTEGER NOT NULL DEFAULT 0,
    rate_cents INTEGER NOT NULL,
    bonus_cents INTEGER NOT NULL DEFAULT 0,
    note TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL CHECK (status IN ('worked', 'planned')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX idx_shifts_date ON shifts (date);

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE device_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    remember INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    expires_at TEXT NOT NULL
);

CREATE TABLE login_attempts (
    ip TEXT NOT NULL,
    attempted_at TEXT NOT NULL
);
CREATE INDEX idx_login_attempts_ip ON login_attempts (ip, attempted_at);
