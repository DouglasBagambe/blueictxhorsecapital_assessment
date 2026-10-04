# Blue ICT x Horse Capital take-home assessment

This project provides a PHP USSD crime reporter, a PostGIS proximity-search API and vanilla-JavaScript dashboard, and a defensive log cleanup utility. It uses no application framework.

## Requirements and setup

- PHP 8.1+ with `pdo_pgsql` and `mbstring`
- PostgreSQL 13+ with PostGIS installed and available to the target database
- Bash, GNU `find`, `realpath`, and `stat`

Create the database and load the database assets:

```bash
createdb crime_assessment
PGPORT=5433 psql -d crime_assessment -f sql/schema.sql
PGPORT=5433 psql -d crime_assessment -f sql/seed_1m.sql
PGPORT=5433 psql -d crime_assessment -f sql/explain.sql
```

The seed script truncates only `crime_reports`, inserts exactly 1,000,000 synthetic rows, and runs `ANALYZE`. Do not use the seed script against a database containing production proximity reports.

Connection settings use these optional environment variables; no password is stored in the repository:

```bash
export DATABASE_DSN='pgsql:host=127.0.0.1;port=5433;dbname=crime_assessment'
export DATABASE_USER='postgres'
export DATABASE_PASSWORD='your-local-password'
```

Run the local server from the project root:

```bash
php -S 127.0.0.1:8080
```

Then open `http://127.0.0.1:8080/dashboard.html`.

## USSD workflow

The menu definition is a nested associative array. Each node describes a prompt plus either a captured field and `next` node, or a terminal handler. `handle()` iterates over input tokens (`1*description*location`) and the node definition; it therefore follows arbitrarily deep menu definitions without duplicating navigation code per screen. Navigation is data-driven, rather than being a collection of per-screen `if`/`switch` branches. A `0` token at any depth resets to the root menu.

Example requests:

```bash
curl -d 'sessionId=256700000000&text=' http://127.0.0.1:8080/ussd.php
curl -d 'sessionId=256700000000&text=1*Suspicious+activity+near+market*Plot+12+Kampala' http://127.0.0.1:8080/ussd.php
curl -d 'sessionId=256700000000&text=2*CRM-ABC123' http://127.0.0.1:8080/ussd.php
```

Status replies use this exact dynamic template:

```text
Dear Citizen, your report [REFERENCE] is currently [STATUS]. Submitted on: [DATE].
```

The replacement values are stripped of CR/LF and bracket characters before insertion.

### Status transitions and administration

The repository performs the update inside a transaction, locks the report with `SELECT ... FOR UPDATE`, checks the transition matrix, updates the report, and inserts a history row before commit. Any failure rolls the transaction back.

| Current status | Allowed next status |
| --- | --- |
| Submitted | Under Review |
| Under Review | Resolved, Rejected |
| Resolved | none (final) |
| Rejected | none (final) |

This rejects Submitted-to-Resolved and Submitted-to-Rejected jumps, as well as every transition after a final state.

```bash
php admin_update.php --ref=CRM-ABC123 --status='Under Review' --by='AdminUser'
php admin_update.php --ref=CRM-ABC123 --status='Resolved' --by='AdminUser'
```

The CLI prints `Success:` on a committed update. Validation errors exit non-zero (2), application/state errors use 3, and database failures use 4.

### Audit logging and PII

Every USSD request appends exactly one locked NDJSON line to `logs/ussd.log` with UTC ISO-8601 timestamp, SHA-256 session identifier, sanitized input and response, action, and millisecond duration. CR, LF, and NUL are removed before JSON encoding, so input cannot create extra log lines. Phone-like values are masked; common `house`, `plot`, `apartment`, `flat`, and `room` address forms are redacted. JSON encoding uses invalid-UTF-8 substitution and one encoded object plus one newline per transaction.

## Proximity search

`crime_reports.location` is `geography(Point, 4326)` with this index:

```sql
CREATE INDEX IF NOT EXISTS crime_reports_location_gist_idx
    ON crime_reports USING GIST (location);
```

The API requires numeric `lat`, `lng`, and `radius_km`; it accepts latitude -90..90, longitude -180..180, and a radius greater than zero up to 50 km. It returns at most 250 records, ordered nearest first. For example:

```bash
curl 'http://127.0.0.1:8080/api/get_reports.php?lat=0.3476&lng=32.5825&radius_km=5'
```

The prepared query is:

```sql
SELECT nearest.id, nearest.description, nearest.status, nearest.created_at,
       ST_Y(nearest.location::geometry) AS lat,
       ST_X(nearest.location::geometry) AS lng,
       ST_Distance(nearest.location, origin.point) AS distance_meters
FROM (
    SELECT ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography AS point
) AS origin
CROSS JOIN LATERAL (
    SELECT report.id, report.location, report.description, report.status,
           report.created_at
    FROM crime_reports AS report
    ORDER BY report.location <-> origin.point
    LIMIT 250
) AS nearest
WHERE ST_DWithin(nearest.location, origin.point, :radius_meters)
ORDER BY distance_meters ASC;
```

The API has a strict 250-result cap. The lateral subquery uses the GiST KNN operator (`<->`) to retrieve the 250 nearest reports efficiently. `ST_DWithin` is retained as the requested-radius filter, so only reports inside the caller's radius are returned. The final exact `ST_Distance` ordering is therefore performed on at most 250 rows rather than every candidate in the radius. A composite B-Tree on separately stored latitude/longitude values does not model circular distance: it can at best narrow a rectangular bounding box and still requires many distance calculations. A GiST geography index stores spatial bounds and supports PostGIS proximity operators, making it suitable for this radius query.

The dashboard debounces input by 350 ms and cancels superseded fetches with `AbortController`. It builds result rows with `textContent`, puts each chunk in a `DocumentFragment`, and yields between 100-row chunks with `requestAnimationFrame`; this avoids one large synchronous DOM insertion blocking the main thread.

Prepared statements send values separately from SQL syntax to PostgreSQL. The driver binds each parameter as data, not executable SQL tokens, so malicious parameter text cannot alter the statement structure.

### Benchmark output

Run the checked-in command after seeding:

```bash
PGPORT=5433 psql -d crime_assessment -f sql/explain.sql
```

The benchmark was run with `SET jit = off`; JIT compilation adds latency without benefiting this short OLTP-style index query. This is a **warm-cache** result, not a cold-cache measurement. The 22.867 ms execution time is below the assessment requirement of **<150 ms**. `shared hit=370` means all 370 needed buffers were served from PostgreSQL shared buffers; no shared reads occurred during this run.

Exact benchmark query:

```sql
SET jit = off;

EXPLAIN (ANALYZE, BUFFERS)
SELECT
    nearest.id,
    nearest.description,
    nearest.status,
    nearest.created_at,
    ST_Distance(nearest.location, origin.point) AS distance_meters
FROM (
    SELECT ST_SetSRID(
        ST_MakePoint(32.5825, 0.3476),
        4326
    )::geography AS point
) AS origin
CROSS JOIN LATERAL (
    SELECT
        report.id,
        report.location,
        report.description,
        report.status,
        report.created_at
    FROM crime_reports AS report
    ORDER BY report.location <-> origin.point
    LIMIT 250
) AS nearest
WHERE ST_DWithin(
    nearest.location,
    origin.point,
    5000
)
ORDER BY distance_meters;
```

Exact warm-cache output:

```text
Sort  (cost=3229.60..3229.60 rows=1 width=64) (actual time=22.644..22.661 rows=250 loops=1)
  Sort Key: (st_distance(nearest.location, '0101000020E6100000C3F5285C8F4A404002BC0512143FD63F'::geography, true))
  Sort Method: quicksort  Memory: 45kB
  Buffers: shared hit=370
  ->  Subquery Scan on nearest  (cost=0.29..3229.59 rows=1 width=64) (actual time=17.996..22.432 rows=250 loops=1)
        Filter: st_dwithin(nearest.location, '0101000020E6100000C3F5285C8F4A404002BC0512143FD63F'::geography, '5000'::double precision, true)
        Buffers: shared hit=370
        ->  Limit  (cost=0.29..89.59 rows=250 width=96) (actual time=17.975..21.343 rows=250 loops=1)
              Buffers: shared hit=370
              ->  Index Scan using crime_reports_location_gist_idx on crime_reports report  (cost=0.29..357204.29 rows=1000000 width=96) (actual time=17.973..21.285 rows=250 loops=1)
                    Order By: (location <-> '0101000020E6100000C3F5285C8F4A404002BC0512143FD63F'::geography)
                    Buffers: shared hit=370
Planning:
  Buffers: shared hit=176
Planning Time: 25.468 ms
Execution Time: 22.867 ms
```

## Defensive log cleanup

```bash
./cleanup.sh /absolute/path/to/logs 7
./cleanup.sh --dry-run /absolute/path/to/logs 7
```

The default threshold is seven days. The script requires an absolute existing directory, rejects a symlink target before canonicalization, canonicalizes via `realpath -e`, and refuses `/`, `/bin`, `/etc`, `/usr`, `/var`, and `/home`. `find -P` does not follow nested symlinks. NUL-delimited `find -print0` input and quoted `rm -- "$file"` safely handle spaces, newlines, leading dashes, and other shell-significant filename content. Relative paths and `../` attempts are rejected before canonicalization.

## Validation and submission checklist

Run the structural checks:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n cleanup.sh
git diff --check
```

For cleanup verification, use a directory under `/tmp` and cover dry-run, deletion, nested directories, space/newline-containing names, symlink target rejection, relative-path rejection, `/var` rejection, and an absolute traversal path such as `/tmp/test/../test` (which canonicalizes safely). With PostgreSQL/PostGIS access, also run schema, seed, USSD creation/status checks, all transition cases, API validation cases, inspect `report_status_history`, validate every log line as JSON, and run the benchmark command.

Before submission confirm: all required files are present; no credentials are committed; PHP and Bash checks pass; cleanup safety cases pass; logs are valid NDJSON; and, where database access is available, the seeded benchmark output is captured exactly with its measured execution time. The project is safe to package after those local validation steps; the unavailable database benchmark remains the only environment-dependent item noted above.
