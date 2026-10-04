<?php
declare(strict_types=1);

require __DIR__ . '/../src/Database.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function decimalParameter(string $name, float $minimum, float $maximum): float
{
    $value = $_GET[$name] ?? null;

    if (!is_string($value) || $value === '' || !is_numeric($value)) {
        respond(400, ['error' => "{$name} is required and must be numeric."]);
    }

    $number = (float)$value;

    if (!is_finite($number) || $number < $minimum || $number > $maximum) {
        respond(400, ['error' => "{$name} must be between {$minimum} and {$maximum}."]);
    }

    return $number;
}

$latitude = decimalParameter('lat', -90, 90);
$longitude = decimalParameter('lng', -180, 180);
$radiusKm = decimalParameter('radius_km', 0.000001, 50);

try {
    $config = require __DIR__ . '/../config.php';
    $pdo = Database::connect($config['db']);
    $statement = $pdo->prepare(
        'SELECT
            nearest.id,
            nearest.description,
            nearest.status,
            nearest.created_at,
            ST_Y(nearest.location::geometry) AS lat,
            ST_X(nearest.location::geometry) AS lng,
            ST_Distance(nearest.location, origin.point) AS distance_meters
        FROM (
            SELECT ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography AS point
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
        WHERE ST_DWithin(nearest.location, origin.point, :radius_meters)
        ORDER BY distance_meters ASC'
    );
    $statement->execute([
        ':lat' => $latitude,
        ':lng' => $longitude,
        ':radius_meters' => $radiusKm * 1000,
    ]);

    respond(200, [
        'reports' => $statement->fetchAll(),
        'limit' => 250,
    ]);
} catch (Throwable $e) {
    error_log('Crime proximity API error: ' . $e->getMessage());
    respond(500, ['error' => 'Service temporarily unavailable.']);
}
