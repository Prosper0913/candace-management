<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/geocoding.php';
require_login();

$user_id = current_user_id();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM shipments WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $user_id]);
$shipment = $stmt->fetch();

if (!$shipment || $shipment['supplier_lat'] === null) {
    set_flash('error', 'No map available for that shipment.');
    header('Location: shipments.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM shipment_items WHERE shipment_id = ? ORDER BY id');
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$progress = shipment_progress_fraction($shipment['created_at'], $shipment['expected_date'], $shipment['status']);
$days_left = (int) round((strtotime($shipment['expected_date']) - strtotime(date('Y-m-d'))) / 86400);
$route_points = $shipment['route_geojson'] ? json_decode($shipment['route_geojson'], true) : null;

$page_title = 'Shipment Map';
$active_nav = 'shipments';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">

<div class="page-head">
    <div>
        <p class="eyebrow">Store</p>
        <h1>Delivery Map</h1>
        <p><?= h($shipment['supplier'] ?: 'Shipment') ?> &rarr; <?= h(STORE_NAME) ?></p>
    </div>
    <div><a href="shipments.php" class="btn-ghost btn" style="text-decoration:none;">Back to Shipments</a></div>
</div>

<div class="card" style="margin-bottom:16px;">
    <div class="form-grid">
        <div class="field"><label>Supplier</label><div><?= h($shipment['supplier'] ?: '—') ?></div></div>
        <div class="field"><label>From</label><div><?= h($shipment['supplier_address']) ?></div></div>
        <div class="field"><label>To</label><div><?= h(STORE_FULL_ADDRESS) ?></div></div>
        <div class="field"><label>Status</label><div><span class="status-pill <?= h($shipment['status']) ?>"><?= h(ucfirst($shipment['status'])) ?></span></div></div>
        <div class="field"><label>Expected</label><div><?= h(display_date($shipment['expected_date'])) ?><?php if ($shipment['status'] === 'pending'): ?> (<?= h(shipment_due_label($days_left)) ?>)<?php endif; ?></div></div>
    </div>
    <?php if (!$route_points): ?>
        <p class="helper-text" style="margin-top:10px;">Showing a straight line between the two points - a driving route couldn't be fetched (needs internet access to a public routing service) when this shipment was created.</p>
    <?php endif; ?>
</div>

<div class="card">
    <div id="shipment-map"></div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
const supplierPoint = [<?= (float) $shipment['supplier_lat'] ?>, <?= (float) $shipment['supplier_lng'] ?>];
const storePoint = [<?= STORE_LAT ?>, <?= STORE_LNG ?>];
const routePoints = <?= $route_points ? json_encode($route_points) : 'null' ?>;
const progress = <?= json_encode($progress) ?>; // 0 = just scheduled (at supplier), 1 = due/arrived (at store)
const status = <?= json_encode($shipment['status']) ?>;

const map = L.map('shipment-map');
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
}).addTo(map);

const path = (routePoints && routePoints.length > 1) ? routePoints : [supplierPoint, storePoint];
const latlngs = path.map(p => L.latLng(p[0], p[1]));

const supplierIcon = L.divIcon({ className: '', html: '<div class="map-pin map-pin-supplier"><span>S</span></div>', iconSize: [26, 26], iconAnchor: [13, 26] });
const storeIcon = L.divIcon({ className: '', html: '<div class="map-pin map-pin-store"><span>C</span></div>', iconSize: [26, 26], iconAnchor: [13, 26] });
const truckIcon = L.divIcon({ className: '', html: '<div class="map-truck">\u{1F69A}</div>', iconSize: [30, 30], iconAnchor: [15, 15] });

L.marker(supplierPoint, { icon: supplierIcon }).addTo(map).bindPopup('Supplier<br>' + <?= json_encode($shipment['supplier'] ?: 'Supplier') ?>);
L.marker(storePoint, { icon: storeIcon }).addTo(map).bindPopup(<?= json_encode(STORE_NAME) ?> + ' (your store)');

L.polyline(latlngs, {
    color: '#17181a',
    weight: 3,
    opacity: 0.8,
    dashArray: routePoints ? null : '6 8', // dashed = approximate straight line, solid = real driving route
}).addTo(map);

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

const truckPosition = pointAtFraction(latlngs, progress);
const truckMarker = L.marker(truckPosition, { icon: truckIcon }).addTo(map);
const pct = Math.round(progress * 100);
truckMarker.bindPopup(status === 'received' ? 'Delivered' : `About ${pct}% of the way there`);

map.fitBounds(L.latLngBounds(latlngs), { padding: [30, 30] });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>