<?php

session_start();

// Stop the browser from showing this page from cache after logout
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");


// ================= CHECK LOGIN + COMMUTER ROLE =================

if (!isset($_SESSION["user_id"], $_SESSION["role"]) || $_SESSION["role"] !== "commuter") {
    header("Location: ../login.php");
    exit;
}


// ================= USER INFORMATION =================
// "?? ''" prevents PHP warnings if a session value is missing

$first_name = $_SESSION["first_name"] ?? "";
$last_name  = $_SESSION["last_name"]  ?? "";
$email      = $_SESSION["email"]      ?? "";

$full_name  = trim($first_name . " " . $last_name);
$initial    = strtoupper(mb_substr($full_name !== "" ? $full_name : "C", 0, 1));

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Commuter Dashboard | SmartMinibus</title>

    <link
        rel="stylesheet"
        href="../css/commuter-dashboard.css?v=<?= (int)@filemtime(__DIR__ . '/../css/commuter-dashboard.css') ?>"
    >
    <link
        rel="stylesheet"
        href="../css/app-polish.css?v=<?= (int)@filemtime(__DIR__ . '/../css/app-polish.css') ?>"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@500;600;700&display=swap"
        rel="stylesheet"
    >

    <!-- Font Awesome -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
    >

    <!-- Leaflet -->
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >

</head>


<body>

<!-- ================= TOP NAVIGATION ================= -->

<header class="top-header">

    <a href="dashboard.php" class="brand-section" aria-label="SmartMinibus Home">
        <div class="brand-logo">
            <img src="../images/logo.png" alt="SmartMinibus Logo">
        </div>

        <div class="brand-copy">
            <div class="brand-name"><span>Smart</span><strong>Minibus</strong></div>
            <div class="brand-tagline">Smart Travel. Smarter Commute</div>
        </div>
    </a>

    <nav class="top-nav" id="topNav" aria-label="Main navigation">
        <a href="#dashboard" class="nav-link active">
            <i class="fa-solid fa-gauge-high"></i> Dashboard
        </a>
        <a href="#tracking" class="nav-link">
            <i class="fa-solid fa-location-dot"></i> My Location
        </a>
        <a href="#notifications" class="nav-link">
            <i class="fa-solid fa-bell"></i> Notifications
            <span class="notification-badge" id="commuterUnreadBadge" hidden>0</span>
        </a>
        <a href="#routeActivity" class="nav-link">
            <i class="fa-solid fa-route"></i> Trips
        </a>
        <a href="#complaint-form" class="nav-link">
            <i class="fa-solid fa-message"></i> Complaints
        </a>
    </nav>

    <div class="profile-menu" id="profileMenu">
        <button
            type="button"
            class="driver-account"
            id="profileToggle"
            aria-haspopup="true"
            aria-expanded="false"
            aria-controls="profileDropdown"
        >
            <span class="driver-avatar"><?= htmlspecialchars($initial) ?></span>

            <span class="driver-info">
                <strong><?= htmlspecialchars($full_name) ?></strong>
                <span>Commuter</span>
            </span>

            <i class="fa-solid fa-chevron-down profile-caret" aria-hidden="true"></i>
        </button>

        <div class="profile-dropdown" id="profileDropdown" role="menu">
            <div class="profile-dropdown-header">
                <span class="driver-avatar"><?= htmlspecialchars($initial) ?></span>

                <span class="driver-info">
                    <strong><?= htmlspecialchars($full_name) ?></strong>
                    <span><?= htmlspecialchars($email) ?></span>
                </span>
            </div>

            <a href="../logout.php" class="profile-logout" role="menuitem">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>

    <button
        type="button"
        class="menu-button"
        id="menuButton"
        aria-label="Open menu"
        aria-expanded="false"
        aria-controls="topNav"
    >
        <i class="fa-solid fa-bars"></i>
    </button>

</header>


<!-- ================= MAIN PAGE ================= -->

<main class="main-content">

    <!-- ================= WELCOME BANNER ================= -->

    <section class="welcome-banner" id="dashboard">
        <div class="welcome-content">
            <span class="welcome-label">Welcome back!</span>

            <h1>
                Hello, <?= htmlspecialchars($full_name) ?>
                <span class="wave">👋</span>
            </h1>

            <p>Find an active minibus and see its estimated arrival time.</p>
        </div>
    </section>


    <!-- ================= DASHBOARD GRID ================= -->

    <section class="grid">

        <!-- ================= LEFT COLUMN ================= -->

        <div class="left-column">

            <!-- ================= MAP ================= -->

            <section class="card map-card" id="tracking">

                <div class="map-wrap">
                    <div id="map"></div>

                    <div class="map-tag start">Start Market</div>
                    <div class="map-tag end">Rivera Road</div>

                    <div class="map-live-tag" id="mapLiveTag">
                        🚌 <span id="mapBusNumber">Waiting for bus</span>&nbsp; • &nbsp;Speed: <b id="mapSpeed">--</b>
                    </div>

                    <div class="map-clock" id="mapClock">--:--</div>
                </div>

                <div class="map-note">
                    Live GPS map • <span id="mapStatusText">Waiting for driver location</span>
                </div>

            </section>


            <!-- ================= LIVE DETAILS ================= -->

            <section class="card live-details-card">

                <div class="section-card-heading">
                    <div>
                        <span>LIVE TRACKING</span>
                        <h2>
                            <span id="displayBusName">Waiting for minibus</span>
                        </h2>

                        <p class="vehicle-plate">
                            Plate No. <span id="displayBusPlate">Not available</span>
                        </p>
                    </div>

                    <div class="live-status" id="liveStatus">
                        <span></span> WAITING
                    </div>
                </div>

                <div class="live-details-grid">

                    <div class="live-detail-main">
                        <div class="mini-route-label">CURRENT ROUTE</div>
                        <h3 id="currentRouteText">Waiting for an active route</h3>
                    </div>

                    <div class="live-stat">
                        <span>Driver</span>
                        <strong id="driverName">Waiting for driver</strong>
                    </div>

                    <div class="live-stat">
                        <span>Current Speed</span>
                        <strong id="busSpeed">--</strong>
                    </div>

                    <div class="live-stat">
                        <span>GPS Accuracy</span>
                        <strong id="gpsAccuracy">--</strong>
                    </div>

                    <div class="live-stat">
                        <span>Next Major Stop ETA</span>
                        <strong class="green-text" id="eta">Waiting for live trip</strong>
                    </div>

                    <div class="live-stat">
                        <span>GPS Location</span>
                        <strong id="busCoordinates">Waiting...</strong>
                    </div>

                </div>

                <div class="updated">
                    ● Last updated: <strong id="lastUpdated">Waiting for GPS</strong>
                </div>

                <div class="location-map-actions">
                    <button class="track-button secondary" id="centerButton" type="button">
                        ⌖ Center Map
                    </button>
                </div>

            </section>


            <!-- ================= SCHEDULED STOPS ================= -->

            <section class="card stops-card">

                <div class="section-card-heading stops-title">
                    <div>
                        <span>SCHEDULE</span>
                        <h2>Scheduled Stops &amp; ETAs</h2>
                    </div>
                </div>

                <div class="stops-head">
                    <span>Seq</span>
                    <span>Stop Location Name</span>
                    <span class="eta-col">Next Bus ETA</span>
                </div>

                <div id="commuterStopsList" aria-live="polite">
                    <div class="stop-detail visible">Choose an active bus to see its assigned route and stops.</div>
                </div>

            </section>

        </div>


        <!-- ================= RIGHT COLUMN ================= -->

        <div class="right-column">

            <!-- ================= ACTIVE MINIBUSES ================= -->

            <section class="card side-card">

                <div class="side-head">
                    <h2>Active Minibuses</h2>
                    <div class="live-dot" id="sideLiveStatus">LIVE</div>
                </div>

                <p class="side-note">Live availability comes from assigned drivers and active trip reports.</p>

                <div id="activeBusesList" aria-live="polite">
                    <p class="side-note">Loading active minibuses...</p>
                </div>

            </section>

            <section class="card side-card" id="routeActivity">
                <div class="side-head"><h2>Current &amp; Past Trips</h2></div>
                <p class="side-note">Trip status and route updates from drivers.</p>
                <div id="routeActivityList" aria-live="polite">
                    <p class="side-note">Loading trip activity...</p>
                </div>
            </section>


            <!-- ================= PERFORMANCE ================= -->

            <section class="card side-card performance-card">

                <div class="side-head">
                    <h2>Route Performance</h2>
                </div>

                <div class="perf-row">
                    <span class="label">Average Dispatch Interval</span>
                    <strong class="value">15 mins</strong>
                </div>

                <div class="perf-row">
                    <span class="label">Peak Hours Occupancy</span>
                    <strong class="value">85%</strong>
                </div>

                <div class="perf-row">
                    <span class="label">On-Time Reliability Rate</span>
                    <strong class="value good">94.2%</strong>
                </div>

            </section>

        </div>

    </section>
    <!-- FIX: the grid is now closed HERE. Before, it stayed open and the
         notifications / complaint sections were trapped inside the 2-column
         grid, and a stray </section> at the bottom closed it. -->


    <!-- ================= NOTIFICATIONS ================= -->

    <section class="notifications-section" id="notifications">

        <div class="section-header">
            <div>
                <span>RECENT UPDATES</span>
                <h2>Notifications</h2>
            </div>
        </div>

        <div class="notifications-list" id="commuterNotifications" aria-live="polite">
            <p class="side-note">Loading notifications...</p>
        </div>

    </section>


    <!-- ================= COMPLAINT ================= -->

    <section class="complaint-section" id="complaint">

        <div>
            <span>NEED HELP?</span>
            <h2>Have a concern about a trip?</h2>
            <p>You can submit a complaint or report an issue about a minibus service.</p>
        </div>

        <a href="#complaint-form" class="complaint-button">Submit a Complaint</a>

    </section>


    <!-- ================= COMPLAINT FORM ================= -->

    <section class="simple-card complaint-form-card" id="complaint-form">

        <div class="section-header">
            <div>
                <span>REPORT AN ISSUE</span>
                <h2>Complaint Form</h2>
            </div>
        </div>

        <form id="complaintForm" action="#" method="post">
            <label for="complaintType">Complaint Type</label>
            <select id="complaintType" name="complaint_type">
                <option value="service">Service issue</option>
                <option value="driver">Driver concern</option>
                <option value="vehicle">Vehicle concern</option>
                <option value="route">Route issue</option>
                <option value="other">Other</option>
            </select>

            <label for="complaintSubject">Subject</label>
            <input type="text" id="complaintSubject" name="subject" maxlength="150" placeholder="Short summary of the issue">

            <label for="complaintMessage">Message</label>
            <textarea id="complaintMessage" name="message" maxlength="3000" placeholder="Describe your concern..." required></textarea>

            <button type="submit" class="btn btn-solid">Submit Complaint</button>

            <div class="form-success" id="complaintSuccess" role="status" hidden>
                Your complaint has been sent to the administrator.
            </div>
            <div class="form-success form-error" id="complaintError" role="alert" hidden>
                Please describe your concern before submitting.
            </div>
        </form>
        <div class="complaint-history">
            <h3>Your complaint updates</h3>
            <div id="commuterComplaintHistory" aria-live="polite">
                <p class="side-note">Loading your complaints...</p>
            </div>
        </div>

    </section>


    <!-- ================= FOOTER ================= -->

    <footer class="site-footer">

        <div class="footer-grid">

            <div class="footer-brand">
                <div class="footer-brand-title">
                    <img src="../images/logo.png" alt="SmartMinibus Logo" class="footer-logo">
                    <span><span class="brand-smart">Smart</span><span class="brand-minibus light">Minibus</span></span>
                </div>

                <p>
                    Empowering Gonzaga commuting with real-time analytics,<br>
                    location intelligence, and automated schedules.
                </p>
            </div>

            <div class="footer-links">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="../index.php">Home</a></li>
                    <li><a href="#notifications">Notifications</a></li>
                    <!-- FIX: "#about" pointed to a section that doesn't exist -->
                    <li><a href="#complaint">Help &amp; Complaints</a></li>
                </ul>
            </div>

            <div class="footer-contact">
                <h4>Contact Us</h4>
                <ul>
                    <li><span class="ci" aria-hidden="true">📞</span><span>+63 955 555 5555</span></li>
                    <li><span class="ci" aria-hidden="true">✉️</span><a href="mailto:support@smartminibus.ph">support@smartminibus.ph</a></li>
                    <li><span class="ci" aria-hidden="true">📍</span><span>Gonzaga, Cagayan, Philippines</span></li>
                </ul>
            </div>

            <div class="footer-social">
                <h4>Follow Us</h4>
                <div class="social-row">
                    <a href="#" aria-label="Facebook">f</a>
                    <a href="#" aria-label="Twitter">𝕏</a>
                    <!-- TODO: replace YOUR_PAGE with your real Instagram handle -->
                    <a href="https://www.instagram.com/YOUR_PAGE"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="Instagram">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="3" y="3" width="18" height="18" rx="5"
                                  fill="none" stroke="currentColor" stroke-width="2"/>
                            <circle cx="12" cy="12" r="4"
                                    fill="none" stroke="currentColor" stroke-width="2"/>
                            <circle cx="17.5" cy="6.5" r="1" fill="currentColor"/>
                        </svg>
                    </a>
                </div>
            </div>

        </div>

        <div class="footer-bottom">
            © 2026 SmartMinibus. All rights reserved.
        </div>

    </footer>

</main>


<!-- ================= LEAFLET ================= -->

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
/* =========================================================
   SMARTMINIBUS COMMUTER LIVE TRACKING
========================================================= */

const $ = (id) => document.getElementById(id);

const defaultLocation = [18.2580, 121.9390];


/* ================= MAP ================= */

const map = L.map("map").setView(defaultLocation, 15);

L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: "&copy; OpenStreetMap contributors"
}).addTo(map);

// Make sure Leaflet measures the container correctly after layout settles
window.addEventListener("load", () => map.invalidateSize());
window.addEventListener("resize", () => map.invalidateSize());


/* ================= MARKERS ================= */

const busIcon = L.divIcon({
    className: "custom-bus-marker",
    html: '<div class="bus-marker-map">🚌</div>',
    iconSize: [45, 45],
    iconAnchor: [22, 22]
});

let busMarker = null;
let currentBusLocation = null;

let hasCenteredOnBus = false;

/* ================= HELPERS ================= */

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
}

function formatTime(dateValue) {
    if (!dateValue) return "Unknown";

    const date = new Date(String(dateValue).replace(" ", "T"));

    if (isNaN(date.getTime())) return String(dateValue);

    return date.toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit"
    });
}

// Real clock on the map (was hard-coded to "10:30 AM")
function updateClock() {
    $("mapClock").textContent = new Date().toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit"
    });
}
updateClock();
setInterval(updateClock, 30000);


/* =========================================================
   LIVE DRIVER LOCATION
========================================================= */

function setOffline(message) {

    $("mapStatusText").textContent = message;
    $("driverName").textContent = "No active driver";
    $("displayBusName").textContent = "No active minibus";
    $("displayBusPlate").textContent = "Not available";
    $("mapBusNumber").textContent = "No bus online";
    $("busSpeed").textContent = "--";
    $("mapSpeed").textContent = "--";
    $("busCoordinates").textContent = "No GPS";
    $("gpsAccuracy").textContent = "--";
    $("lastUpdated").textContent = "No GPS data";
    $("eta").textContent = "Waiting for live trip";
    $("currentRouteText").textContent = "Waiting for an active route";

    $("liveStatus").innerHTML = "<span></span> OFFLINE";
    $("sideLiveStatus").textContent = "OFFLINE";

    if (busMarker) { map.removeLayer(busMarker); busMarker = null; }
    currentBusLocation = null;
}


function getDriverLocation() {

    fetch("../api/get_driver_location.php")

        .then(response => {
            if (!response.ok) throw new Error("Network response was not OK.");
            return response.json();
        })

        .then(data => {

            if (!(data.success && data.location)) {
                setOffline("Waiting for GPS");
                return;
            }

            const loc       = data.location;
            const latitude  = parseFloat(loc.latitude);
            const longitude = parseFloat(loc.longitude);
            const accuracy  = parseFloat(loc.accuracy || 0);
            const speed     = parseFloat(loc.speed || 0);

            // FIX: bad/empty coordinates would make Leaflet throw and kill the update
            if (isNaN(latitude) || isNaN(longitude)) {
                setOffline("Invalid GPS data");
                return;
            }

            currentBusLocation = [latitude, longitude];

            // Bus marker
            if (!busMarker) {
                busMarker = L.marker(currentBusLocation, { icon: busIcon }).addTo(map);
            } else {
                busMarker.setLatLng(currentBusLocation);
            }

            const driverName = loc.driver_name || "Unknown Driver";

            // FIX: calling bindPopup every 5 s closed the popup while someone
            // was reading it. Update the content in place instead.
            const popupHtml =
                "<strong>Bus " + escapeHtml(loc.bus_number || "—") + "</strong><br>" +
                escapeHtml(loc.route_name || "Route not assigned") + "<br>" +
                "Driver: " + escapeHtml(driverName) + "<br>" +
                "Speed: " + speed.toFixed(1) + " km/h<br>" +
                "Accuracy: " + accuracy.toFixed(1) + " m";

            if (busMarker.getPopup()) {
                busMarker.setPopupContent(popupHtml);
            } else {
                busMarker.bindPopup(popupHtml);
            }

            // Details
            const speedText = speed.toFixed(1) + " km/h";

            $("driverName").textContent    = driverName;
            $("displayBusName").textContent = "Bus " + (loc.bus_number || "—");
            $("displayBusPlate").textContent = loc.plate_number || "Not available";
            $("mapBusNumber").textContent = "Bus " + (loc.bus_number || "—");
            $("busSpeed").textContent      = speedText;
            $("mapSpeed").textContent      = speedText;   // was never updated
            $("busCoordinates").textContent = latitude.toFixed(5) + ", " + longitude.toFixed(5);
            $("gpsAccuracy").textContent   = accuracy.toFixed(1) + " m";
            $("lastUpdated").textContent   = formatTime(loc.recorded_at);
            $("currentRouteText").textContent = [loc.origin, loc.destination].filter(Boolean).join(" → ") || loc.route_name || "Route not assigned";
            $("eta").textContent = estimateTripEta(loc);

            $("mapStatusText").textContent = "GPS Online";
            $("liveStatus").innerHTML      = "<span></span> LIVE";
            $("sideLiveStatus").textContent = "LIVE";

            // Center once on the first real bus position
            if (!hasCenteredOnBus) {
                map.setView(currentBusLocation, 15);
                hasCenteredOnBus = true;
            }
        })

        .catch(error => {
            console.error("Driver location error:", error);
            // FIX: on a network error the page kept showing "LIVE" forever
            setOffline("Connection problem");
        });
}

function estimateTripEta(location) {
    const reportedSpeed = Number(location.speed);
    const speed = reportedSpeed > 0 ? reportedSpeed : 25;
    if (location.dest_lat !== null && location.dest_lng !== null &&
        location.latitude !== null && location.longitude !== null &&
        Number.isFinite(Number(location.latitude)) && Number.isFinite(Number(location.longitude))) {
        const distance = distanceKm(
            Number(location.latitude),
            Number(location.longitude),
            Number(location.dest_lat),
            Number(location.dest_lng)
        );
        if (distance < .15) return "Near destination";
        const minutes = Math.max(1, Math.ceil(distance * 1.3 / speed * 60));
        return reportedSpeed > 0
            ? "Approx. " + minutes + " min to destination"
            : "Approx. " + minutes + " min (using 25 km/h average)";
    }
    if (!location.est_minutes) return "ETA unavailable";
    if (!location.trip_started_at) return Number(location.est_minutes) + " min route estimate";
    const startedAt = new Date(String(location.trip_started_at).replace(" ", "T"));
    if (Number.isNaN(startedAt.getTime())) return Number(location.est_minutes) + " min route estimate";
    const elapsed = Math.max(0, Math.floor((Date.now() - startedAt.getTime()) / 60000));
    const remaining = Math.max(0, Number(location.est_minutes) - elapsed);
    return remaining === 0 ? "Trip in progress" : remaining + " min route estimate";
}


/* =========================================================
   CENTER MAP
   FIX: now wired with addEventListener instead of inline onclick
========================================================= */

$("centerButton").addEventListener("click", function () {

    if (!currentBusLocation) {
        alert("The minibus GPS location is not available yet.");
        return;
    }

    map.setView(currentBusLocation, 17);

    if (busMarker) busMarker.openPopup();
});


/* =========================================================
   SCHEDULED STOPS: click a row to see its details
   FIX: rows had data-stop attributes but nothing happened on click
========================================================= */

(function () {

    const rows   = document.querySelectorAll(".stop-row");
    const detail = $("stopDetail");

    rows.forEach(function (row) {
        row.addEventListener("click", function () {
            rows.forEach(r => r.classList.remove("selected"));
            row.classList.add("selected");
            detail.textContent = row.dataset.detail || "";
            detail.classList.add("visible");
        });
    });

})();


/* =========================================================
   COMMUTER TRIPS, BUSES, NOTIFICATIONS AND COMPLAINTS
========================================================= */

function renderActiveBuses(buses) {
    const list = $("activeBusesList");
    if (!buses.length) {
        list.innerHTML = '<p class="side-note">No active minibuses are registered right now.</p>';
        return;
    }

    list.innerHTML = buses.map(function (bus) {
        const seatText = bus.seat_status === "full" ? "Full" :
            (bus.seat_status === "available" ? "Seats available" : "Not reported");
        const seatClass = bus.seat_status === "full" ? "full" :
            (bus.seat_status === "available" ? "ok" : "unknown");
        const liveText = bus.online ? "GPS live" : "Location unavailable";
        const tripText = bus.trip_status === "active" ? "Departed" : "Not departed";
        const eta = bus.trip_status === "active" ? estimateTripEta({
            latitude: bus.latitude,
            longitude: bus.longitude,
            dest_lat: bus.dest_lat,
            dest_lng: bus.dest_lng,
            speed: bus.speed,
            trip_started_at: bus.started_at,
            est_minutes: bus.est_minutes
        }) : "Not departed";
        return '<div class="bus-block">' +
            '<div class="bus-top"><div class="bus-id"><span class="bus-icon">🚌</span> Bus ' + escapeHtml(bus.bus_number) + '</div>' +
            '<span class="seat-tag ' + seatClass + '">' + seatText + '</span></div>' +
            '<div class="bus-row"><span class="label">Route</span><strong class="value">' + escapeHtml(bus.route_name || "No route assigned") + '</strong></div>' +
            '<div class="bus-row"><span class="label">Trip status</span><strong class="value">' + tripText + '</strong></div>' +
            '<div class="bus-row"><span class="label">Next arrival</span><strong class="value">' + eta + '</strong></div>' +
            '<div class="bus-row"><span class="label">GPS</span><strong class="value">' + liveText + '</strong></div>' +
            '<div class="bus-row"><span class="label">Last updated</span><strong class="value">' + (bus.recorded_at ? formatTime(bus.recorded_at) : "No location yet") + '</strong></div>' +
            '</div>';
    }).join("");
}

function distanceKm(aLat, aLng, bLat, bLng) {
    const radians = function(value) { return value * Math.PI / 180; };
    const dLat = radians(bLat - aLat);
    const dLng = radians(bLng - aLng);
    const a = Math.sin(dLat / 2) ** 2 +
        Math.cos(radians(aLat)) * Math.cos(radians(bLat)) * Math.sin(dLng / 2) ** 2;
    return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function renderScheduledStops(buses, routes) {
    const list = $("commuterStopsList");
    const bus = buses.find(function(item) { return item.online && item.trip_status === "active"; }) ||
        buses.find(function(item) { return item.online; });
    if (!bus || !bus.route_id) {
        list.innerHTML = '<div class="stop-detail visible">No active bus is reporting GPS on an assigned route yet.</div>';
        return;
    }

    let stops = routes[String(bus.route_id)] || routes[bus.route_id] || [];
    if (!stops.length) {
        stops = [
            { stop_name: bus.origin || "Route origin", latitude: null, longitude: null, stop_sequence: 1 },
            { stop_name: bus.destination || "Route destination", latitude: null, longitude: null, stop_sequence: 2 }
        ];
    }
    const speed = Number(bus.speed) > 0 ? Number(bus.speed) : 20;
    const busHasGps = bus.latitude !== null && bus.longitude !== null &&
        Number.isFinite(Number(bus.latitude)) && Number.isFinite(Number(bus.longitude));
    const departed = bus.trip_status === "active";
    const rows = stops.map(function(stop, index) {
        let etaText = departed ? "In progress" : "Not departed";
        let distanceText = "Route stop";
        if (departed && busHasGps && stop.latitude !== null && stop.longitude !== null &&
            Number.isFinite(Number(stop.latitude)) && Number.isFinite(Number(stop.longitude))) {
            const km = distanceKm(Number(bus.latitude), Number(bus.longitude), Number(stop.latitude), Number(stop.longitude));
            distanceText = km.toFixed(1) + " km away";
            etaText = km < .15 ? "At this stop" : "~" + Math.max(1, Math.ceil(km * 1.4 / speed * 60)) + " min";
        }
        const state = departed && index === 0 ? "Departed" : etaText;
        return '<div class="stop-row' + (index === 0 ? " selected" : "") + '" tabindex="0" data-stop-detail="' +
            escapeHtml(bus.bus_number + " · " + (bus.route_name || "Assigned route") + " · " + state) + '">' +
            '<div class="stop-seq">' + (Number(stop.stop_sequence) || index + 1) + '</div><div><div class="stop-name">' +
            escapeHtml(stop.stop_name) + '</div><div class="stop-dist">' + distanceText + '</div></div>' +
            '<div class="stop-eta' + (departed && index === 0 ? " done" : "") + '">' + state +
            '<span>via Bus ' + escapeHtml(bus.bus_number) + '</span></div></div>';
    }).join("");
    list.innerHTML = rows + '<div class="stop-detail visible" id="stopDetail">' +
        escapeHtml(bus.bus_number + " · " + (bus.route_name || "Assigned route") + " · " +
            (departed ? "Trip departed" : "Waiting for driver departure")) + "</div>";

    list.querySelectorAll(".stop-row").forEach(function(row) {
        row.addEventListener("click", function() {
            list.querySelectorAll(".stop-row").forEach(function(item) { item.classList.remove("selected"); });
            row.classList.add("selected");
            $("stopDetail").textContent = row.dataset.stopDetail || "";
        });
        row.addEventListener("keydown", function(event) {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                row.click();
            }
        });
    });
}

function renderRouteActivity(trips) {
    const list = $("routeActivityList");
    if (!trips.length) {
        list.innerHTML = '<p class="side-note">No trip departures have been reported yet.</p>';
        return;
    }

    list.innerHTML = trips.map(function (trip) {
        const status = trip.status === "active" ? "Departed · Live trip" :
            (trip.status === "completed" ? "Completed" : "Cancelled");
        return '<div class="bus-block">' +
            '<div class="bus-top"><div class="bus-id">' + escapeHtml(trip.bus_number) + '</div><span class="seat-tag ' +
            (trip.status === "active" ? "ok" : "unknown") + '">' + status + '</span></div>' +
            '<div class="bus-row"><span class="label">Route</span><strong class="value">' +
            escapeHtml((trip.origin || "") + " → " + (trip.destination || trip.route_name || "")) + '</strong></div>' +
            '<div class="bus-row"><span class="label">Departure</span><strong class="value">' + formatTime(trip.started_at) + '</strong></div>' +
            (trip.ended_at ? '<div class="bus-row"><span class="label">Arrival</span><strong class="value">' + formatTime(trip.ended_at) + '</strong></div>' : '') +
            '</div>';
    }).join("");
}

function renderComplaintHistory(complaints) {
    const list = $("commuterComplaintHistory");
    if (!complaints.length) {
        list.innerHTML = '<p class="side-note">You have not submitted any complaints.</p>';
        return;
    }
    list.innerHTML = complaints.map(function (complaint) {
        return '<div class="notification-item"><div class="notification-icon orange">!</div><div><strong>' +
            escapeHtml(complaint.subject) + ' · ' + escapeHtml(complaint.status) +
            '</strong><p>' + escapeHtml(complaint.message) + '</p>' +
            (complaint.admin_response ? '<p><strong>Admin response:</strong> ' + escapeHtml(complaint.admin_response) + '</p>' : '') +
            '<small>' + formatTime(complaint.updated_at || complaint.created_at) + '</small></div></div>';
    }).join("");
}

function loadCommuterDashboard() {
    fetch("../api/commuter_dashboard.php", { credentials: "same-origin", cache: "no-store" })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (!data.success) throw new Error(data.message || "Unable to load active buses.");
            renderActiveBuses(data.buses || []);
            renderScheduledStops(data.buses || [], data.route_stops || {});
            renderRouteActivity(data.trips || []);
            renderComplaintHistory(data.complaints || []);
        })
        .catch(function (error) {
            console.error("Commuter dashboard data error:", error);
            $("activeBusesList").innerHTML = '<p class="side-note">Could not load active buses. Please refresh.</p>';
            $("routeActivityList").innerHTML = '<p class="side-note">Could not load trip history.</p>';
            $("commuterComplaintHistory").innerHTML = '<p class="side-note">Could not load complaint updates.</p>';
        });
}

function loadCommuterNotifications() {
    fetch("../api/communication.php?action=notification_list", { credentials: "same-origin", cache: "no-store" })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (!result.ok) throw new Error(result.message || "Unable to load notifications.");
            const list = $("commuterNotifications");
            if (!result.data.length) {
                list.innerHTML = '<p class="side-note">No notifications yet.</p>';
                $("commuterUnreadBadge").hidden = true;
                return;
            }
            const unread = result.data.filter(function(item) { return !Number(item.is_read); }).length;
            $("commuterUnreadBadge").textContent = unread > 99 ? "99+" : String(unread);
            $("commuterUnreadBadge").hidden = unread === 0;
            list.innerHTML = result.data.map(function (item) {
                return '<article class="notification-item"><div class="notification-icon blue"><i class="fa-solid fa-bell"></i></div><div>' +
                    '<strong>' + escapeHtml(item.title) + '</strong><p>' + escapeHtml(item.message) + '</p><small>' +
                    escapeHtml(item.sender_name || "SmartMinibus") + ' · ' + formatTime(item.created_at) +
                    (Number(item.is_read) ? ' · Read' : ' · New') + '</small>' +
                    (Number(item.is_read) ? '' : '<button type="button" class="mark-notification-read" data-notification-id="' + Number(item.notification_id) + '">Mark as read</button>') +
                    '</div></article>';
            }).join("");
        })
        .catch(function (error) {
            console.error("Notification loading error:", error);
            $("commuterNotifications").innerHTML = '<p class="side-note">Could not load notifications. Please refresh.</p>';
        });
}

const commuterForm = $("complaintForm");
commuterForm.addEventListener("submit", function (event) {
    event.preventDefault();
    const success = $("complaintSuccess");
    const error = $("complaintError");
    success.hidden = true;
    error.hidden = true;

    if (!commuterForm.reportValidity()) return;
    const submit = commuterForm.querySelector('[type="submit"]');
    submit.disabled = true;
    const payload = new FormData(commuterForm);
    payload.set("action", "complaint_submit");
    fetch("../api/communication.php", {
        method: "POST",
        body: payload,
        credentials: "same-origin"
    })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (!result.ok) throw new Error(result.message || "Unable to submit complaint.");
            success.textContent = "Complaint submitted successfully. The administrator has been notified.";
            success.hidden = false;
            commuterForm.reset();
            loadCommuterDashboard();
        })
        .catch(function (submitError) {
            error.textContent = submitError.message;
            error.hidden = false;
        })
        .finally(function () { submit.disabled = false; });
});

$("commuterNotifications").addEventListener("click", function (event) {
    const button = event.target.closest("[data-notification-id]");
    if (!button) return;
    const payload = new FormData();
    payload.append("action", "notification_read");
    payload.append("notification_id", button.dataset.notificationId);
    fetch("../api/communication.php", { method: "POST", body: payload, credentials: "same-origin" })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (!result.ok) throw new Error(result.message || "Unable to mark notification as read.");
            loadCommuterNotifications();
        })
        .catch(function (error) { console.error("Notification update error:", error); });
});

loadCommuterDashboard();
loadCommuterNotifications();
setInterval(function () {
    if (!document.hidden) loadCommuterDashboard();
}, 15000);
setInterval(function () {
    if (!document.hidden) loadCommuterNotifications();
}, 30000);
getDriverLocation();
setInterval(getDriverLocation, 5000);

</script>


<!-- Header menu + profile dropdown (independent of map scripts) -->
<script>
(function () {

    const menuButton    = document.getElementById("menuButton");
    const topNav        = document.getElementById("topNav");
    const profileMenu   = document.getElementById("profileMenu");
    const profileToggle = document.getElementById("profileToggle");

    function setNav(open) {
        topNav.classList.toggle("open", open);
        menuButton.setAttribute("aria-expanded", open);
        menuButton.querySelector("i").className =
            open ? "fa-solid fa-xmark" : "fa-solid fa-bars";
    }

    function setProfile(open) {
        profileMenu.classList.toggle("open", open);
        profileToggle.setAttribute("aria-expanded", open);
    }

    menuButton.addEventListener("click", function (event) {
        event.stopPropagation();
        setProfile(false);
        setNav(!topNav.classList.contains("open"));
    });

    profileToggle.addEventListener("click", function (event) {
        event.stopPropagation();
        setNav(false);
        setProfile(!profileMenu.classList.contains("open"));
    });

    document.addEventListener("click", function (event) {
        if (!profileMenu.contains(event.target)) setProfile(false);
        if (!topNav.contains(event.target) && !menuButton.contains(event.target)) setNav(false);
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") { setProfile(false); setNav(false); }
    });

    window.addEventListener("resize", function () {
        if (window.innerWidth > 1024) setNav(false);
    });

    // Active link follows clicks and scroll position
    const links = Array.from(document.querySelectorAll(".top-nav .nav-link"));

    function setActive(hash) {
        links.forEach(function (link) {
            link.classList.toggle("active", link.getAttribute("href") === hash);
        });
    }

    links.forEach(function (link) {
        link.addEventListener("click", function () {
            setActive(link.getAttribute("href"));
            setNav(false);
        });
    });

    const sections = links
        .map(function (link) { return document.querySelector(link.getAttribute("href")); })
        .filter(Boolean);

    window.addEventListener("scroll", function () {
        const offset = window.scrollY + 140;
        let current = "#dashboard";

        sections.forEach(function (section) {
            if (section.offsetTop <= offset) current = "#" + section.id;
        });

        setActive(current);
    }, { passive: true });

})();
</script>

</body>

</html>