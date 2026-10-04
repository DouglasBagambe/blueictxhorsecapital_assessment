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
