<?php
/**
 * Turns a plain-text address into map coordinates, and gets a driving route
 * between two points - both using free, keyless public services:
 *
 *  - Geocoding: OpenStreetMap's Nominatim (https://nominatim.org)
 *  - Routing:   OSRM's public demo server (https://project-osrm.org)
 *
 * Neither requires an account or API key. Both are shared community
 * infrastructure with light rate limits (roughly 1 request/second), which is
 * fine here since we only call them once per shipment, at creation time, and
 * cache the result in the database - not on every page view.
 *
 * IMPORTANT: These reach out to the public internet. If this server has no
 * outbound internet access (e.g. a fully offline POS PC), both functions will
 * simply return null and the map will fall back to a straight line between
 * two manually-approximate points - nothing else in the app depends on this
 * working.
 */

/**
 * Geocodes a free-text address to [lat, lng], or null if it couldn't be found
 * or the request failed for any reason (no internet, timeout, etc).
 */
function geocode_address(string $address): ?array
{
    if (trim($address) === '') return null;

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $address,
        'format' => 'json',
        'limit' => 1,
        'countrycodes' => 'ph', // this store is in the Philippines; narrows ambiguous matches
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => NOMINATIM_USER_AGENT, // required by Nominatim's usage policy
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $ok = $response !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);

    if (!$ok) return null;

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) return null;

    return [(float) $data[0]['lat'], (float) $data[0]['lon']];
}

/**
 * Gets a driving route between two [lat, lng] points as a list of [lat, lng]
 * waypoints (already reordered from OSRM's [lng, lat] GeoJSON convention so
 * callers don't have to remember which order is which). Returns null if the
 * request fails - callers should fall back to a straight line in that case.
 */
function get_route_points(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
{
    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F?overview=full&geometries=geojson',
        $fromLng, $fromLat, $toLng, $toLat
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => NOMINATIM_USER_AGENT,
    ]);
    $response = curl_exec($ch);
    $ok = $response !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);

    if (!$ok) return null;

    $data = json_decode($response, true);
    $coords = $data['routes'][0]['geometry']['coordinates'] ?? null;
    if (!is_array($coords) || count($coords) < 2) return null;

    // OSRM gives [lng, lat] pairs - flip to [lat, lng] for Leaflet/our own use.
    return array_map(fn($pair) => [(float) $pair[1], (float) $pair[0]], $coords);
}

/**
 * How far along (0.0 to 1.0) a shipment is between being placed and arriving,
 * based on elapsed time. Used to position the truck on the map: 0 = just
 * scheduled (truck at the supplier), 1 = due today or overdue (truck at the
 * store). Received shipments are always 1 (already arrived).
 */
function shipment_progress_fraction(string $createdAt, string $expectedDate, string $status): float
{
    if ($status === 'received') return 1.0;

    $start = strtotime($createdAt);
    $end = strtotime($expectedDate . ' 23:59:59');
    $now = time();

    if ($end <= $start) return 1.0; // guard against a same-day or backdated order
    $fraction = ($now - $start) / ($end - $start);

    return max(0.0, min(1.0, $fraction));
}