CREATE EXTENSION IF NOT EXISTS postgis;

CREATE TABLE IF NOT EXISTS ussd_reports (
    id BIGSERIAL PRIMARY KEY,
    reference VARCHAR(10) NOT NULL UNIQUE,
    description TEXT NOT NULL,
    location_text TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'Submitted'
        CHECK (status IN ('Submitted', 'Under Review', 'Resolved', 'Rejected')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS report_status_history (
    id BIGSERIAL PRIMARY KEY,
    report_id BIGINT NOT NULL REFERENCES ussd_reports(id) ON DELETE CASCADE,
    old_status VARCHAR(20) NOT NULL,
    new_status VARCHAR(20) NOT NULL,
    updated_by VARCHAR(120) NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS report_status_history_report_id_updated_at_idx
    ON report_status_history (report_id, updated_at);

CREATE TABLE IF NOT EXISTS crime_reports (
    id BIGSERIAL PRIMARY KEY,
    location geography(Point, 4326) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL
        CHECK (status IN ('Submitted', 'Under Review', 'Resolved', 'Rejected')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS crime_reports_location_gist_idx
    ON crime_reports USING GIST (location);
