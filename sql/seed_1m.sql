-- This script deliberately leaves exactly one million synthetic proximity rows.
TRUNCATE TABLE crime_reports RESTART IDENTITY;

INSERT INTO crime_reports (location, description, status, created_at)
SELECT
    ST_SetSRID(
        ST_MakePoint(
            32.25 + random() * 0.70,
            0.10 + random() * 0.50
        ),
        4326
    )::geography,
    'Synthetic crime report #' || series_id,
    (ARRAY['Submitted', 'Under Review', 'Resolved', 'Rejected'])[1 + floor(random() * 4)::int],
    NOW() - (random() * INTERVAL '365 days')
FROM generate_series(1, 1000000) AS series_id;

ANALYZE crime_reports;
