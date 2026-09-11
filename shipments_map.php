<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/geocoding.php';
require_login();

$user_id = current_user_id();

$stmt = $pdo->prepare(
    'SELECT s.*, COALESCE(SUM(si.line_total), 0) AS total_cost, COUNT(si.id) AS item_count
     FROM shipments s
     LEFT JOIN shipment_items si ON si.shipment_id = s.id
     WHERE s.user_id = ? AND s.status = "pending" AND s.supplier_lat IS NOT NULL
     GROUP BY s.id
     ORDER BY s.expected_date ASC'
);
$stmt->execute([$user_id]);
$shipments = $stmt->fetchAll();

// Build the data each shipment's truck/route needs, client-side.
$map_shipments = array_map(function ($s) {
    $days_left = (int) round((strtotime($s['expected_date']) - strtotime(date('Y-m-d'))) / 86400);
    return [
        'id' => (int) $s['id'],
        'supplier' => $s['supplier'] ?: 'Shipment',
        'lat' => (float) $s['supplier_lat'],
        'lng' => (float) $s['supplier_lng'],
        'route' => $s['route_geojson'] ? json_decode($s['route_geojson'], true) : null,
        'progress' => shipment_progress_fraction($s['created_at'], $s['expected_date'], $s['status']),
        'due_label' => shipment_due_label($days_left),
        'expected' => display_date($s['expected_date']),
        'item_count' => (int) $s['item_count'],
        'total_cost' => (float) $s['total_cost'],
    ];
}, $shipments);

$page_title = 'All Upcoming Shipments Map';
$active_nav = 'shipments';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">

<div class="page-head">
    <div>
        <p class="eyebrow">Store</p>
        <h1>All Upcoming Shipments</h1>
        <p><?= count($map_shipments) ?> shipment(s) with a pinned supplier location, heading to <?= h(STORE_NAME) ?>.</p>
    </div>
    <a href="shipments.php" class="btn-ghost btn" style="text-decoration:none;">Back to Shipments</a>
</div>

<?php if (!$map_shipments): ?>
    <div class="card">
        <div class="empty-state">
            No upcoming shipments have a pinned supplier location yet. Add a supplier address (and click Search to pin it) when scheduling a shipment on the
            <a href="shipments.php">Shipments</a> page.
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div id="all-shipments-map"></div>
    </div>

    <div class="card" style="margin-top:16px;">
        <div class="card-title">Legend</div>
        <table>
            <thead><tr><th>Supplier</th><th>Items</th><th class="amount">Cost</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($map_shipments as $s): ?>
                <tr>
                    <td><?= h($s['supplier']) ?></td>
                    <td><?= $s['item_count'] ?> item(s)</td>
                    <td class="amount"><?= peso($s['total_cost']) ?></td>
                    <td><?= h(ucfirst($s['due_label'])) ?> (<?= h($s['expected']) ?>)</td>
                    <td class="actions"><a class="icon-link" href="shipment_map.php?id=<?= $s['id'] ?>">Detail map</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
<?php if ($map_shipments): ?>
const storePoint = [<?= STORE_LAT ?>, <?= STORE_LNG ?>];
const shipmentsData = <?= json_encode($map_shipments) ?>;

const map = L.map('all-shipments-map');
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
}).addTo(map);

const storeIcon = L.divIcon({ className: '', html: '<div class="map-pin map-pin-store"><span>C</span></div>', iconSize: [26, 26], iconAnchor: [13, 26] });
const supplierIcon = L.divIcon({ className: '', html: '<div class="map-pin map-pin-supplier"><span>S</span></div>', iconSize: [26, 26], iconAnchor: [13, 26] });
const truckIcon = L.divIcon({ className: '', html: '<div class="map-truck">\u{1F69A}</div>', iconSize: [28, 28], iconAnchor: [14, 14] });

function pointAtFraction(points, fraction) {
    if (points.length === 1) return points[0];
    const segmentDistances = [];
    let total = 0;
    for (let i = 1; i < points.length; i++) {
        const d = points[i - 1].distanceTo(points[i]);
        segmentDistances.push(d);
        total += d;
    }
    if (total === 0) return points[0];
    const target = total * fraction;
    let covered = 0;
    for (let i = 0; i < segmentDistances.length; i++) {
        if (covered + segmentDistances[i] >= target) {
            const remain = target - covered;
            const ratio = segmentDistances[i] === 0 ? 0 : remain / segmentDistances[i];
            const a = points[i], b = points[i + 1];
            return L.latLng(a.lat + (b.lat - a.lat) * ratio, a.lng + (b.lng - a.lng) * ratio);
        }
        covered += segmentDistances[i];
    }
    return points[points.length - 1];
}

const allLatLngs = [L.latLng(storePoint[0], storePoint[1])];

L.marker(storePoint, { icon: storeIcon }).addTo(map).bindPopup(<?= json_encode(STORE_NAME) ?> + ' (your store)');

shipmentsData.forEach(s => {
    const supplierPoint = [s.lat, s.lng];
    const path = (s.route && s.route.length > 1) ? s.route : [supplierPoint, storePoint];
    const latlngs = path.map(p => L.latLng(p[0], p[1]));
    latlngs.forEach(ll => allLatLngs.push(ll));

    L.marker(supplierPoint, { icon: supplierIcon }).addTo(map).bindPopup(`<strong>${s.supplier}</strong><br>Supplier location`);

    L.polyline(latlngs, {
        color: '#17181a',
        weight: 2,
        opacity: 0.6,
        dashArray: s.route ? null : '6 8',
    }).addTo(map);

    const truckPosition = pointAtFraction(latlngs, s.progress);
    const pct = Math.round(s.progress * 100);
    L.marker(truckPosition, { icon: truckIcon }).addTo(map)
        .bindPopup(`<strong>${s.supplier}</strong><br>${s.item_count} item(s), \u20b1${s.total_cost.toFixed(2)}<br>${s.due_label} (${s.expected})<br>About ${pct}% of the way there`);
});

map.fitBounds(L.latLngBounds(allLatLngs), { padding: [30, 30] });
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>