#!/usr/bin/env python3
"""Convert a MySQL dump of the Yard Picker Pro database into SQL for D1.

Keeps every row id, because Stripe checkout sessions carry the user id in
their metadata. Old bcrypt password hashes are kept as they are; the Worker
accepts them once and replaces them on that user's next login.

1. Dump the data only, with TIMESTAMP values in UTC (the default):

     mysqldump --no-create-info --skip-extended-insert --complete-insert \
       --tz-utc -u USER -p DBNAME users credit_events tracker_items > dump.sql

   (phpMyAdmin works too: Export > Custom > Data only, with
   "Dump TIMESTAMP columns in UTC" ticked and "Complete inserts" chosen.)

2. Convert:   python3 scripts/mysql-to-d1.py dump.sql > d1-data.sql
3. Load into an empty D1 database that already has the migration applied:
     npx wrangler d1 execute yardpicker --remote --file d1-data.sql

The dump and d1-data.sql hold email addresses and password hashes: keep them
out of git and delete them once the import is checked.
"""
import re
import sys

# Tables and columns the D1 schema has (migrations/0001_init.sql).
# password_resets is skipped: open reset links are short-lived anyway.
TABLES = {
    "users": ["id", "email", "password_hash", "credits", "free_credits_used", "free_period_start", "created_at"],
    "credit_events": ["id", "user_id", "delta", "reason", "ref", "created_at"],
    "tracker_items": ["id", "user_id", "part", "car", "lkq_cost", "labor_cost", "ebay_avg", "list_price",
                      "sale_price", "reason", "status", "pulled_at", "listed_at", "sold_at", "created_at"],
}
ORDER = ["users", "credit_events", "tracker_items"]  # parents first (foreign keys)
SKIPPED = {"password_resets"}

INSERT_RE = re.compile(r"INSERT\s+INTO\s+`?(\w+)`?\s*\(([^)]*)\)\s*VALUES\s*", re.I)
ESCAPES = {"0": "\0", "b": "\b", "n": "\n", "r": "\r", "t": "\t", "Z": "\x1a", "\\": "\\", "'": "'", '"': '"'}


def parse_values(s, i):
    """Parse (v, v, ...), (v, ...) ... ; starting at s[i]. Returns (rows, next index)."""
    rows = []
    n = len(s)
    while i < n:
        while i < n and s[i] in " \t\r\n,":
            i += 1
        if i < n and s[i] == ";":
            return rows, i + 1
        if i >= n or s[i] != "(":
            raise ValueError(f"expected '(' at offset {i}")
        i += 1
        row = []
        while True:
            while s[i] in " \t\r\n":
                i += 1
            if s[i] == "'":
                i += 1
                buf = []
                while True:
                    c = s[i]
                    if c == "\\":
                        buf.append(ESCAPES.get(s[i + 1], s[i + 1]))
                        i += 2
                    elif c == "'" and i + 1 < n and s[i + 1] == "'":
                        buf.append("'")
                        i += 2
                    elif c == "'":
                        i += 1
                        break
                    else:
                        buf.append(c)
                        i += 1
                row.append("".join(buf))
            else:
                m = re.compile(r"NULL|-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?", re.I).match(s, i)
                if not m:
                    raise ValueError(f"unsupported value at offset {i}: {s[i:i + 20]!r}")
                tok = m.group(0)
                row.append(None if tok.upper() == "NULL" else tok)
                i = m.end()
            while s[i] in " \t\r\n":
                i += 1
            if s[i] == ",":
                i += 1
                continue
            if s[i] == ")":
                i += 1
                break
            raise ValueError(f"unexpected {s[i]!r} at offset {i}")
        rows.append(row)
    return rows, i


def sqlite_literal(v, quoted):
    if v is None:
        return "NULL"
    if not quoted:
        return v
    if "\0" in v:
        raise ValueError("NUL byte in a text value")
    return "'" + v.replace("'", "''") + "'"


def convert(text):
    out = {t: [] for t in ORDER}
    i = 0
    while True:
        m = INSERT_RE.search(text, i)
        if not m:
            break
        table = m.group(1)
        cols = [c.strip().strip("`") for c in m.group(2).split(",")]
        start = m.end()
        # Track which values were quoted so numbers stay numbers.
        rows, i = parse_values(text, start)
        if table in SKIPPED:
            continue
        if table not in TABLES:
            sys.stderr.write(f"skipping unknown table {table}\n")
            continue
        unknown = set(cols) - set(TABLES[table])
        if unknown:
            raise SystemExit(f"{table}: columns not in the D1 schema: {sorted(unknown)}")
        quoted_cols = quoted_columns(text[start:i], len(cols))
        for row in rows:
            if len(row) != len(cols):
                raise SystemExit(f"{table}: row has {len(row)} values for {len(cols)} columns")
            vals = ", ".join(sqlite_literal(v, q) for v, q in zip(row, quoted_cols))
            out[table].append(f"INSERT INTO {table} ({', '.join(cols)}) VALUES ({vals});")
    if not any(out.values()):
        raise SystemExit("no INSERT statements found. Was the dump made with --complete-insert?")
    lines = []
    for t in ORDER:
        lines += out[t]
        sys.stderr.write(f"{t}: {len(out[t])} rows\n")
    return "\n".join(lines) + "\n"


def quoted_columns(values_sql, ncols):
    """Which columns hold text: true where any row quotes that column."""
    quoted = [False] * ncols
    col, depth, in_str, i = 0, 0, False, 0
    while i < len(values_sql):
        c = values_sql[i]
        if in_str:
            if c == "\\":
                i += 1
            elif c == "'":
                in_str = False
        elif c == "'":
            in_str = True
            quoted[col] = True
        elif c == "(":
            depth, col = 1, 0
        elif c == "," and depth:
            col += 1
        elif c == ")":
            depth = 0
        i += 1
    return quoted


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("usage: mysql-to-d1.py dump.sql > d1-data.sql")
    with open(sys.argv[1], encoding="utf-8") as f:
        sys.stdout.write(convert(f.read()))
