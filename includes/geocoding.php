<?php
/**
 * Turns text addresses into map coordinates, looks up real places when the
 * owner clicks "Search", and gets a driving route between two points - all
 * via LocationIQ's free tier (https://locationiq.com).
 *
 * WHY LOCATIONIQ INSTEAD OF NOMINATIM/OSRM DIRECTLY:
 * Nominatim's and OSRM's public demo servers are shared community
 * infrastructure meant for light manual testing, not for embedding in an
 * app people actually use - their policies explicitly forbid it, and their
 * servers actively detect and block that pattern (a 403 with the body
 * "Access denied. See https://operations.osmfoundation.org/policies/nominatim/"
 * is exactly that). LocationIQ is built on the same OpenStreetMap data, but
 * its free tier (5,000 requests/day, 2/second) explicitly permits this kind
 * of embedded, commercial use - which is what an actual deployed store
 * system is. Its `/search` endpoint is documented as Nominatim-compatible in
 * response shape (same `display_name` / `lat` / `lon` fields), so the
 * parsing logic below barely differs from what a raw-Nominatim version
 * would look like.
 *
 * SETUP: get a free key at https://locationiq.com/ and paste it into
 * LOCATIONIQ_API_KEY in config/config.php. Until then, every function below
 * returns a clear "no API key configured" message instead of a confusing
 * failure.
 *
 * IMPORTANT: These still reach out to the public internet. If this server
 * has no outbound internet access (a fully offline POS PC), all three
 * functions below return null/empty with a clear reason - the map then
 * falls back to a straight line between two manually-approximate points.
 * Nothing else in the app depends on this working.
 */

/**
 * Looks up real places matching the text the owner searched for (triggered
 * by an explicit "Search" button click - never automatically while typing).
 * Returns ['results' => [...], 'error' => null] on success (results may be
 * empty if genuinely nothing matched), or ['results' => [], 'error' => '...']
 * if the request itself failed.
 */
function search_places(string $query, int $limit = 5): array
{
    $query = trim($query);
    if (strlen($query) < 3) return ['results' => [], 'error' => null]; // too short to bother searching

    if (LOCATIONIQ_API_KEY === '') {
        return ['results' => [], 'error' => 'No LocationIQ API key configured yet - see the comment above LOCATIONIQ_API_KEY in config/config.php.'];
    }

    $url = 'https://us1.locationiq.com/v1/search?' . http_build_query([
        'key' => LOCATIONIQ_API_KEY,
        'q' => $query,
        'format' => 'json',
        'limit' => $limit,
        'countrycodes' => 'ph', // this store is in the Philippines; narrows ambiguous matches
        'addressdetails' => 0,
    ]);

    [$response, $error] = locationiq_request($url);
    if ($response === null) return ['results' => [], 'error' => $error];

    $data = json_decode($response, true);
    if (!is_array($data)) return ['results' => [], 'error' => 'Unexpected response from the address search service.'];

    $results = [];
    foreach ($data as $place) {
        if (empty($place['display_name']) || !isset($place['lat'], $place['lon'])) continue;
        $results[] = [
            'label' => $place['display_name'],
            'lat' => (float) $place['lat'],
            'lng' => (float) $place['lon'],
        ];
    }
    return ['results' => $results, 'error' => null];
}

/**
 * Geocodes a single free-text address to [lat, lng], or null if it couldn't
 * be found or the request failed for any reason. Used as a fallback when a
 * shipment is saved with a typed address that wasn't picked from
 * search_places()'s suggestions.
 */
function geocode_address(string $address): ?array
{
    if (trim($address) === '' || LOCATIONIQ_API_KEY === '') return null;

    $url = 'https://us1.locationiq.com/v1/search?' . http_build_query([
        'key' => LOCATIONIQ_API_KEY,
        'q' => $address,
        'format' => 'json',
        'limit' => 1,
        'countrycodes' => 'ph',
    ]);

    [$response, $error] = locationiq_request($url);
    if ($response === null) return null;

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) return null;

    return [(float) $data[0]['lat'], (float) $data[0]['lon']];
}

/**
 * Shared cURL request helper - one place for the timeout, User-Agent, and
 * error handling. Returns [body, error]: on success, body is the response
 * string and error is null; on failure, body is null and error is a
 * human-readable reason (network unreachable, bad API key, rate limit, etc).
 */
function locationiq_request(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => NOMINATIM_USER_AGENT,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        // Common on Windows/XAMPP: "SSL certificate problem" means PHP's
        // cURL has no CA bundle configured (curl.cainfo in php.ini).
        return [null, $curl_error !== '' ? "Connection failed: {$curl_error}" : 'Connection failed (no internet access from this server?).'];
    }

    // LocationIQ's documented error codes - give a specific, actionable
    // message for each rather than a bare status number.
    switch ($http_code) {
        case 200:
            return [$response, null];
        case 401:
            return [null, 'LocationIQ says the API key is invalid - double-check LOCATIONIQ_API_KEY in config/config.php.'];
        case 403:
            return [null, 'LocationIQ access restricted for this key/request.'];
        case 404:
            return ['[]', null]; // "unable to geocode" - treat as zero results, not an error
        case 429:
            return [null, "LocationIQ's free-tier rate limit was hit - wait a moment and try again."];
        default:
            return [null, "Address search service returned HTTP {$http_code}."];
    }
}

/**
 * Gets a driving route between two [lat, lng] points as a list of [lat, lng]
 * waypoints (LocationIQ's Directions API mirrors OSRM's [lng, lat] GeoJSON
 * convention, flipped here to [lat, lng] so callers don't have to remember
 * which order is which). Returns null if the request fails - callers should
 * fall back to a straight line in that case.
 */
function get_route_points(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
{
    if (LOCATIONIQ_API_KEY === '') return null;

    $url = sprintf(
        'https://us1.locationiq.com/v1/directions/driving/%F,%F;%F,%F?%s',
        $fromLng, $fromLat, $toLng, $toLat,
        http_build_query(['key' => LOCATIONIQ_API_KEY, 'overview' => 'full', 'geometries' => 'geojson'])
    );

    [$response, ] = locationiq_request($url);
    if ($response === null) return null;

    $data = json_decode($response, true);
    $coords = $data['routes'][0]['geometry']['coordinates'] ?? null;
    if (!is_array($coords) || count($coords) < 2) return null;

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