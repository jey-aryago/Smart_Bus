<?php

session_start();

if (!isset($_SESSION["user_id"], $_SESSION["role"])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION["role"] !== "driver") {
    header("Location: ../login.php");
    exit;
}

$first_name = $_SESSION["first_name"];
$last_name = $_SESSION["last_name"];
$email = $_SESSION["email"];

// ==========================================
// ASSIGNED MINIBUS + ROUTE (from database)
// ==========================================
// TODO: change this path to your real database connection file.
// That file must create a PDO connection named $conn.
$dbFile = __DIR__ . "/../config/database.php";

$assigned_bus   = "Not assigned";
$assigned_plate = "";
$assigned_route = "Not assigned";
$assigned_origin = "";
$assigned_destination = "";
$route_origin_lat = 17.6132000;
$route_origin_lng = 121.7270000;
$route_dest_lat = 18.4614000;
$route_dest_lng = 122.1398000;
$assigned_minibus_id = null;
$assigned_route_id = null;

if (is_file($dbFile)) {
    require_once $dbFile;

    if (isset($conn) && $conn instanceof PDO) {
        $stmt = $conn->prepare(
            "SELECT m.minibus_id, m.bus_number, m.plate_number, m.route_id,
                    r.route_name, r.origin, r.destination,
                    r.origin_lat, r.origin_lng, r.dest_lat, r.dest_lng
             FROM minibuses m
             LEFT JOIN routes r ON r.route_id = m.route_id
             WHERE m.driver_id = ? AND m.status = 'active'
             ORDER BY m.minibus_id ASC
             LIMIT 1"
        );

        if ($stmt) {
            $stmt->execute([(int)$_SESSION["user_id"]]);
            $row = $stmt->fetch();
            $stmt->closeCursor();

            if ($row) {
                $assigned_minibus_id = (int)$row["minibus_id"];
                $assigned_route_id = $row["route_id"] !== null ? (int)$row["route_id"] : null;
                $assigned_bus = "Bus #" . $row["bus_number"];
                $assigned_plate = $row["plate_number"] ?? "";
                $assigned_route = $row["route_name"] ?? "No route yet";
                $assigned_origin = $row["origin"] ?? "";
                $assigned_destination = $row["destination"] ?? "";

                if ($row["origin_lat"] !== null && $row["origin_lng"] !== null) {
                    $route_origin_lat = (float)$row["origin_lat"];
                    $route_origin_lng = (float)$row["origin_lng"];
                }
                if ($row["dest_lat"] !== null && $row["dest_lng"] !== null) {
                    $route_dest_lat = (float)$row["dest_lat"];
                    $route_dest_lng = (float)$row["dest_lng"];
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Driver Dashboard - SmartMinibus</title>
    <link
        rel="stylesheet"
        href="../css/driver-dashboard.css?v=<?php echo @filemtime(__DIR__ . '/../css/driver-dashboard.css'); ?>">
    <link
        rel="stylesheet"
        href="../css/app-polish.css?v=<?php echo @filemtime(__DIR__ . '/../css/app-polish.css'); ?>">

    <!-- Leaflet CSS -->
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

    <!-- Font Awesome -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

</head>

<body>

<div class="dashboard-container">
    <!-- ==========================================
         TOP NAVIGATION HEADER (fixed)
    =========================================== -->

    <header class="top-header" id="topHeader">

        <a href="dashboard.php" class="brand-section">
            <div class="brand-logo">
                <img src="../images/logo.png" alt="SmartMinibus Logo">
            </div>

            <div class="brand-copy">
                <div class="brand-name">
                    <span>Smart</span><strong>Minibus</strong>
                </div>

                <div class="brand-tagline">
                    Smart Travel. Smarter Commute
                </div>
            </div>
        </a>

        <nav class="top-nav" id="topNav" aria-label="Main navigation">
            <a
                href="dashboard.php"
                class="nav-link active">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </a>

            <a
                href="#locationSection"
                class="nav-link">
                <i class="fas fa-location-dot"></i>
                <span>My Location</span>
            </a>

            <a
                href="#routeSection"
                class="nav-link">
                <i class="fas fa-route"></i>
                <span>My Route</span>
            </a>

            <a
                href="#notificationSection"
                class="nav-link">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <span class="notification-badge">
                    2
                </span>
            </a>
        </nav>

        <div class="profile-menu" id="profileMenu">

            <button
                class="driver-account"
                id="profileButton"
                type="button"
                aria-haspopup="true"
                aria-expanded="false"
                aria-controls="profileDropdown">

                <div class="driver-avatar">
                    <?php
                    echo strtoupper(
                        substr($first_name, 0, 1)
                    );
                    ?>
                </div>

                <div class="driver-info">
                    <strong>
                        <?php
                        echo htmlspecialchars($first_name . ' ' . $last_name);
                        ?>
                    </strong>

                    <span>
                        Driver
                    </span>
                </div>

                <i class="fas fa-chevron-down profile-caret"></i>

            </button>

            <div
                class="profile-dropdown"
                id="profileDropdown"
                role="menu">

                <div class="profile-dropdown-header">
                    <div class="driver-avatar">
                        <?php
                        echo strtoupper(
                            substr($first_name . ' ' . $last_name, 0, 1)
                        );
                        ?>
                    </div>

                    <div class="driver-info">
                        <strong>
                            <?php
                            echo htmlspecialchars($first_name . ' ' . $last_name);
                            ?>
                        </strong>

                        <span>
                            Driver
                        </span>
                    </div>
                </div>

                <a
                    href="../logout.php"
                    class="profile-logout"
                    role="menuitem">
                    <i class="fas fa-right-from-bracket"></i>
                    <span>Logout</span>
                </a>

            </div>

        </div>

        <button
            class="menu-button"
            id="menuButton"
            type="button"
            aria-label="Toggle navigation"
            aria-controls="topNav"
            aria-expanded="false">
            <i class="fas fa-bars"></i>
        </button>

    </header>

    <!-- ==========================================
         MAIN CONTENT
    =========================================== -->

    <main class="main-content">

        <!-- ==========================================
             CONTENT
        =========================================== -->

        <section class="content">
            <!-- WELCOME CARD -->

            <div class="welcome-card">
                <div>
                    <span class="small-label">
                        WELCOME BACK
                    </span>

                    <h2>
                        Hello,
                        <?php
                        echo htmlspecialchars($first_name . ' ' . $last_name);
                        ?>!
                    </h2>

                    <p>
                        Check your assigned route and
                        share your actual GPS location
                        with SmartMinibus.
                    </p>
                </div>

                <div class="welcome-icon">
                    <i class="fas fa-bus"></i>
                </div>

            </div>

            <!-- ==========================================
                 STAT CARDS
            =========================================== -->
            <div class="stats-grid">

                <div class="stat-card">

                    <div class="stat-icon blue">
                        <i class="fas fa-bus"></i>
                    </div>

                    <div>
                        <span>
                            Assigned Minibus
                        </span>

                        <h3>
                            <?php echo htmlspecialchars($assigned_bus); ?>
                        </h3>
                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-icon green">
                        <i class="fas fa-route"></i>
                    </div>

                    <div>
                        <span>
                            Assigned Route
                        </span>

                        <h3>
                            <?php echo htmlspecialchars($assigned_route); ?>
                        </h3>
                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-icon orange">
                        <i class="fas fa-gauge-high"></i>
                    </div>

                    <div>
                        <span>
                            Current Speed
                        </span>

                        <h3 id="speedValue">
                            Waiting...
                        </h3>
                    </div>

                </div>



                <div class="stat-card">

                    <div class="stat-icon sky">
                        <i class="fas fa-signal"></i>
                    </div>

                    <div>
                        <span>
                            GPS Status
                        </span>

                        <h3
                            class="gps-online"
                            id="gpsStatus">
                            <span></span>
                            Location Off
                        </h3>
                    </div>
                </div>
            </div>

            <!-- ==========================================
                 CURRENT TRIP
            =========================================== -->
            <div
                class="section-header"
                id="routeSection">
                <div>
                    <h2>
                        Current Trip
                    </h2>

                    <p>
                        Control your trip and GPS location.
                    </p>
                </div>

                <div
                    class="trip-status"
                    id="tripStatus">

                    <span class="status-dot"></span>
                    Trip not started
                </div>

            </div>

            <div class="trip-controls">
                <button type="button" class="btn btn-primary" id="startTripButton">Start Trip / Depart</button>
                <button type="button" class="btn btn-outline" id="endTripButton" disabled>Complete Trip</button>
                <p id="tripActionMessage" role="status" aria-live="polite"></p>
            </div>

            <div class="trip-grid">

                <!-- ROUTE INFORMATION -->
                <div class="route-card">
                    <div class="card-heading">
                        <div>
                            <span class="small-label">
                                ASSIGNED ROUTE
                            </span>

                            <h3>
                                <?php echo htmlspecialchars($assigned_route); ?>
                            </h3>
                        </div>

                        <span class="route-status">
                            Active
                        </span>
                    </div>

                    <div class="route-path">

                        <div class="route-point">

                            <div class="point-icon">
                                <i class="fas fa-location-dot"></i>
                            </div>

                            <div>
                                <span>
                                    Starting Point
                                </span>

                                <strong>
                                    <?php echo htmlspecialchars($assigned_origin ?: 'Starting Point'); ?>
                                </strong>
                            </div>

                        </div>

                        <div class="route-line"></div>

                        <div class="route-point">

                            <div class="point-icon destination">
                                <i class="fas fa-flag-checkered"></i>
                            </div>

                            <div>
                                <span>
                                    Destination
                                </span>

                                <strong>
                                    <?php echo htmlspecialchars($assigned_destination ?: 'Destination'); ?>
                                </strong>
                            </div>

                        </div>

                    </div>


                    <div class="route-details">

                        <div>
                            <span>
                                Bus Number
                            </span>

                            <strong>
                                <?php echo htmlspecialchars($assigned_bus); ?>
                            </strong>
                        </div>

                    </div>

                </div>

                <div class="control-card occupancy-card">
                    <div class="card-heading">
                        <div>
                            <span class="small-label">PASSENGER AVAILABILITY</span>
                            <h3>Seat Status</h3>
                        </div>
                        <i class="fas fa-users control-icon"></i>
                    </div>
                    <p class="control-description">Update this when the minibus becomes full or has available seats.</p>
                    <div class="occupancy-actions">
                        <button type="button" class="btn btn-success" id="seatsAvailableButton">Seats Available</button>
                        <button type="button" class="btn btn-outline" id="busFullButton">Bus Full</button>
                    </div>
                    <p id="seatStatusMessage" role="status" aria-live="polite">Choose the current seat status.</p>
                </div>

                <!-- GPS CONTROL -->

                <div
                    class="control-card"
                    id="locationSection">

                    <div class="card-heading">
                        <div>
                            <span class="small-label">
                                GPS TRACKING
                            </span>

                            <h3>
                                Location Sharing
                            </h3>

                        </div>

                        <i
                            class="fas fa-location-crosshairs control-icon">
                        </i>

                    </div>


                    <p class="control-description">
                        Your actual GPS location is shared
                        with the SmartMinibus system so
                        commuters can see your minibus location.
                    </p>

                    <div class="location-status">
                        <div class="status-circle">
                            <i class="fas fa-location-dot"></i>
                        </div>

                        <div>
                            <strong id="locationStatus">
                                Location sharing is off
                            </strong>

                            <span id="lastUpdate">
                                Click the button below to
                                enable GPS.
                            </span>
                        </div>

                    </div>

                    <button
                        type="button"
                        class="location-button"
                        id="locationButton">

                        <i class="fas fa-location-dot"></i>
                        Enable Location Sharing
                    </button>

                </div>

            </div>

            <!-- ==========================================
                 MAP
            =========================================== -->
            <div
                class="section-header map-header">

                <div>
                    <h2>
                        Live Tracking Map
                    </h2>

                    <p>
                        See your live GPS location and assigned bus route.
                    </p>
                </div>

                <span
                    class="update-time"
                    id="updateTime">

                    <i class="fas fa-clock"></i>
                    Location not active
                </span>

            </div>

            <div class="map-card">

                <div id="driverMap"></div>
                <div class="map-overlay">

                    <div class="map-info">
                        <span>
                            Current Speed
                        </span>

                        <strong id="mapSpeed">
                            Waiting...
                        </strong>
                    </div>

                    <div class="map-info">
                        <span>
                            GPS Accuracy
                        </span>

                        <strong id="gpsAccuracy">
                            Waiting...
                        </strong>
                    </div>

                </div>

            </div>

            <!-- ==========================================
                 GPS INFORMATION
            =========================================== -->

            <div class="gps-information">

                <div class="gps-info-item">
                    <i class="fas fa-globe"></i>

                    <div>
                        <span>
                            Latitude
                        </span>

                        <strong id="latitude">
                            Waiting...
                        </strong>
                    </div>
                </div>


                <div class="gps-info-item">
                    <i class="fas fa-globe"></i>

                    <div>
                        <span>
                            Longitude
                        </span>

                        <strong id="longitude">
                            Waiting...
                        </strong>
                    </div>

                </div>


                <div class="gps-info-item">
                    <i class="fas fa-crosshairs"></i>

                    <div>
                        <span>
                            Accuracy
                        </span>

                        <strong id="accuracy">
                            Waiting...
                        </strong>
                    </div>

                </div>

            </div>


            <!-- ==========================================
                 TODAY'S TRIP
            =========================================== -->
            <div class="section-header">
                <div>
                    <h2>
                        Today's Trip
                    </h2>

                    <p>
                        Your trip information for today.
                    </p>
                </div>
            </div>

            <div class="trip-summary">
                <div class="summary-item">
                    <i class="fas fa-clock"></i>

                    <div>
                        <span>
                            Departure
                        </span>

                        <strong id="tripDeparture">--</strong>
                    </div>
                </div>

                <div class="summary-item">
                    <i class="fas fa-road"></i>

                    <div>
                        <span>
                            Distance
                        </span>

                        <strong id="tripDistance">--</strong>
                    </div>
                </div>

                <div class="summary-item">
                    <i class="fas fa-stopwatch"></i>

                    <div>
                        <span>
                            Estimated Time
                        </span>

                        <strong id="tripEta">--</strong>
                    </div>
                </div>


                <div class="summary-item">
                    <i class="fas fa-users"></i>

                    <div>
                        <span>
                            Passengers
                        </span>

                        <strong id="tripPassengers">--</strong>
                    </div>
                </div>

            </div>


            <!-- ==========================================
                 NOTIFICATIONS
            =========================================== -->
            <div
                class="section-header notification-heading"
                id="notificationSection">

                <div>
                    <h2>
                        Recent Notifications
                    </h2>

                    <p>
                        Latest updates from the administrator.
                    </p>
                </div>

                <a
                    href="#"
                    class="view-all">
                    View All
                </a>

            </div>


            <div class="notifications" id="driverNotifications" aria-live="polite">
                <p>Loading notifications...</p>
            </div>

        </section>

    <!-- ================= FOOTER ================= -->
    <footer class="site-footer">
        <div class="footer-content">

            <div class="footer-brand">
                <div class="footer-brand-title">
                    <img src="../images/logo.png" alt="SmartMinibus Logo">
                    <span>
                        <span class="footer-smart">Smart</span><span class="footer-minibus">Minibus</span>
                    </span>
                </div>
                <p>Empowering Gonzaga commuting with real-time analytics,<br>
                location intelligence, and automated schedules.</p>
            </div>

            <div class="footer-links">
                <h4>Quick Links</h4>
                <div class="footer-link-row">
                    <a href="../index.php">Home</a>
                    <i></i>
                    <a href="#">Notifications</a>
                    <i></i>
                    <a href="#">About</a>
                </div>
            </div>

            <div class="footer-contact">
                <h4>Contact Us</h4>
                <p>📞 <span>+63 955 555 5555</span></p>
                <p>✉️ <a href="mailto:support@smartminibus.ph">support@smartminibus.ph</a></p>
                <p>📍 <span>Gonzaga, Cagayan, Philippines</span></p>
            </div>

            <div class="footer-social">
                <h4>Follow Us</h4>
                <div class="social-icons">
                    <a href="https://www.facebook.com/YOUR_PAGE" target="_blank" rel="noopener noreferrer" aria-label="Facebook">f</a>
                    <a href="https://x.com/YOUR_PAGE" target="_blank" rel="noopener noreferrer" aria-label="X">𝕏</a>
                    <a href="https://www.instagram.com/YOUR_PAGE"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="Instagram">

                        <svg viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="3" y="3" width="18" height="18" rx="5"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"/>

                        <circle cx="12" cy="12" r="4"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"/>

                        <circle cx="17.5" cy="6.5" r="1"
                                fill="currentColor"/>
                        </svg>
                    </a>
                </div>
            </div>

        </div>

        <div class="footer-bottom">© <?php echo date("Y"); ?> SmartMinibus. All rights reserved.</div>
    </footer>

    </main>

</div>


<!-- ==========================================
     LEAFLET JS
=========================================== -->

<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js">
</script>

<script>

// ==========================================
// MOBILE NAVIGATION + PROFILE DROPDOWN
// ==========================================

const menuButton =
    document.getElementById("menuButton");

const topNav =
    document.getElementById("topNav");

const profileMenu =
    document.getElementById("profileMenu");

const profileButton =
    document.getElementById("profileButton");


function setNavOpen(isOpen) {

    topNav.classList.toggle("open", isOpen);

    menuButton.setAttribute(
        "aria-expanded",
        isOpen ? "true" : "false"
    );

}


function setProfileOpen(isOpen) {

    profileMenu.classList.toggle("open", isOpen);

    profileButton.setAttribute(
        "aria-expanded",
        isOpen ? "true" : "false"
    );

}


menuButton.addEventListener(
    "click",
    function(event) {

        event.stopPropagation();

        setProfileOpen(false);

        setNavOpen(
            !topNav.classList.contains("open")
        );

    }
);


profileButton.addEventListener(
    "click",
    function(event) {

        event.stopPropagation();

        setNavOpen(false);

        setProfileOpen(
            !profileMenu.classList.contains("open")
        );

    }
);


document.addEventListener(
    "click",
    function(event) {

        if (
            topNav.classList.contains("open") &&
            !topNav.contains(event.target) &&
            !menuButton.contains(event.target)
        ) {

            setNavOpen(false);

        }

        if (
            profileMenu.classList.contains("open") &&
            !profileMenu.contains(event.target)
        ) {

            setProfileOpen(false);

        }

    }
);


document.addEventListener(
    "keydown",
    function(event) {

        if (event.key === "Escape") {

            if (profileMenu.classList.contains("open")) {

                setProfileOpen(false);

                profileButton.focus();

            }

            setNavOpen(false);

        }

    }
);


// Close the dropdown when a link is tapped
document
    .querySelectorAll(".top-nav .nav-link")
    .forEach(function(link) {

        link.addEventListener(
            "click",
            function() {

                setNavOpen(false);

            }
        );

    });


// Keep the highlighted nav link in sync with the page
(function() {

    var links = Array.prototype.slice.call(
        document.querySelectorAll(".top-nav .nav-link")
    );

    var targets = links.map(function(link) {
        var href = link.getAttribute("href");
        return href.charAt(0) === "#"
            ? document.querySelector(href)
            : null;
    });

    var clickedIndex = -1;
    var idleTimer = null;

    function setActive(index) {
        links.forEach(function(link, i) {
            link.classList.toggle("active", i === index);
        });
    }

    function updateActive() {
        var headerH = document.getElementById("topHeader").offsetHeight;
        var marker = headerH + 80;
        var bestIndex = 0;       // Dashboard by default
        var bestTop = -Infinity;

        // Pick the section closest above the marker
        // (works even if the page order differs from the menu order)
        targets.forEach(function(section, i) {
            if (!section) return;
            var top = section.getBoundingClientRect().top;
            if (top <= marker && top > bestTop) {
                bestTop = top;
                bestIndex = i;
            }
        });

        // Bottom of page: the last section can never reach the marker,
        // so highlight the lowest section that is visible
        if (window.innerHeight + window.scrollY >=
            document.documentElement.scrollHeight - 4) {
            var lowestTop = -Infinity;
            targets.forEach(function(section, i) {
                if (!section) return;
                var top = section.getBoundingClientRect().top;
                if (top < window.innerHeight && top > lowestTop) {
                    lowestTop = top;
                    bestIndex = i;
                }
            });
        }

        setActive(bestIndex);
    }

    links.forEach(function(link, i) {
        link.addEventListener("click", function() {
            if (!targets[i]) return;
            clickedIndex = i;
            setActive(i);
        });
    });

    window.addEventListener("scroll", function() {

        // While a clicked link is scrolling into view, keep it highlighted
        if (clickedIndex !== -1) {
            setActive(clickedIndex);
            clearTimeout(idleTimer);
            idleTimer = setTimeout(function() {
                clickedIndex = -1;
                updateActive();
            }, 200);
            return;
        }

        updateActive();

    }, { passive: true });

    window.addEventListener("load", updateActive);
    updateActive();

})();

// Reset when returning to the desktop layout
window.addEventListener(
    "resize",
    function() {

        if (window.innerWidth > 1024) {

            setNavOpen(false);

        }

    }
);

// ==========================================
// COLORS (read from driver-dashboard.css)
// ==========================================

const rootStyles =
    getComputedStyle(document.documentElement);

const COLORS = {
    green: rootStyles.getPropertyValue("--green").trim(),
    sky: rootStyles.getPropertyValue("--sky").trim(),
    red: rootStyles.getPropertyValue("--red").trim(),
    blue: rootStyles.getPropertyValue("--blue").trim(),
    muted: rootStyles.getPropertyValue("--muted").trim()
};

// ==========================================
// MAP
// ==========================================

const ASSIGNED = {
    bus: <?php echo json_encode($assigned_bus); ?>,
    plate: <?php echo json_encode($assigned_plate); ?>,
    route: <?php echo json_encode($assigned_route); ?>,
    routeId: <?php echo $assigned_route_id === null ? "null" : (int)$assigned_route_id; ?>,
    minibusId: <?php echo $assigned_minibus_id === null ? "null" : (int)$assigned_minibus_id; ?>,
    origin: <?php echo json_encode($assigned_origin); ?>,
    destination: <?php echo json_encode($assigned_destination); ?>,
    originLat: <?php echo json_encode($route_origin_lat); ?>,
    originLng: <?php echo json_encode($route_origin_lng); ?>,
    destinationLat: <?php echo json_encode($route_dest_lat); ?>,
    destinationLng: <?php echo json_encode($route_dest_lng); ?>
};

const defaultLocation = [
    Number(ASSIGNED.originLat) || 17.6132,
    Number(ASSIGNED.originLng) || 121.7270
];


const map =
    L.map("driverMap")
        .setView(
            defaultLocation,
            15
        );


L.tileLayer(
    "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
    {

        maxZoom: 19,

        attribution:
            "&copy; OpenStreetMap contributors"

    }
).addTo(map);

// Assigned route preview. This is route data from MySQL, not a fake
// driver position. The bus marker itself is created only from live GPS.
const originPoint = [Number(ASSIGNED.originLat), Number(ASSIGNED.originLng)];
const destinationPoint = [Number(ASSIGNED.destinationLat), Number(ASSIGNED.destinationLng)];

if (originPoint.every(Number.isFinite) && destinationPoint.every(Number.isFinite)) {
    L.polyline([originPoint, destinationPoint], {
        color: COLORS.blue,
        weight: 3,
        opacity: 0.45,
        dashArray: "6, 8"
    }).addTo(map);
}


// ==========================================
// DRIVER BUS ICON
// ==========================================

const busIcon =
    L.divIcon({

        className:
            "driver-map-marker",

        html: `
            <div class="bus-marker">
                <i class="fas fa-bus"></i>
            </div>
        `,

        iconSize:
            [44, 44],

        iconAnchor:
            [22, 22]

    });

// ==========================================
// DRIVER VARIABLES
// ==========================================

let driverMarker = null;

let accuracyCircle = null;

let locationWatcher = null;

let sharingLocation = false;


// ==========================================
// HTML ELEMENTS
// ==========================================

const locationStatus =
    document.getElementById(
        "locationStatus"
    );

const lastUpdate =
    document.getElementById(
        "lastUpdate"
    );

const locationButton =
    document.getElementById(
        "locationButton"
    );

const gpsStatus =
    document.getElementById(
        "gpsStatus"
    );

const speedValue =
    document.getElementById(
        "speedValue"
    );

const mapSpeed =
    document.getElementById(
        "mapSpeed"
    );

const gpsAccuracy =
    document.getElementById(
        "gpsAccuracy"
    );

const latitudeElement =
    document.getElementById(
        "latitude"
    );

const longitudeElement =
    document.getElementById(
        "longitude"
    );

const accuracyElement =
    document.getElementById(
        "accuracy"
    );

const updateTime =
    document.getElementById(
        "updateTime"
    );

// ==========================================
// SEND DRIVER LOCATION TO DATABASE
// ==========================================

function sendLocationToServer(
    latitude,
    longitude,
    accuracy,
    speed,
    heading
) {
    const formData = new FormData();
    formData.append("latitude", latitude);
    formData.append("longitude", longitude);
    formData.append("accuracy", accuracy);
    if (speed !== null && Number.isFinite(speed)) formData.append("speed", speed);
    if (heading !== null && Number.isFinite(heading)) formData.append("heading", heading);

    return fetch("../api/update_location.php", {
        method: "POST",
        body: formData,
        credentials: "same-origin",
        cache: "no-store"
    })
    .then(async function(response) {
        const data = await response.json().catch(function() {
            return { success: false, message: "Server returned invalid JSON." };
        });
        if (!response.ok || !data.success) {
            throw new Error(data.message || "Unable to save GPS location.");
        }
        return data;
    })
    .catch(function(error) {
        console.error("Database location error:", error);
        lastUpdate.textContent = "GPS received, but the server did not save it: " + error.message;
        return null;
    });
}


// ==========================================
// GPS SUCCESS
// ==========================================

function handleLocation(position) {

    const latitude =
        position.coords.latitude;


    const longitude =
        position.coords.longitude;


    const accuracy =
        position.coords.accuracy;



    // ======================================
    // SPEED
    // ======================================

    let speed = null;


    if (
        position.coords.speed !== null &&
        !isNaN(position.coords.speed)
    ) {

        speed =
            position.coords.speed * 3.6;

    }



    // ======================================
    // HEADING
    // ======================================

    let heading = null;


    if (
        position.coords.heading !== null &&
        !isNaN(position.coords.heading)
    ) {

        heading =
            position.coords.heading;

    }



    const currentLocation =
        [
            latitude,
            longitude
        ];



    // ======================================
    // CREATE / MOVE DRIVER MARKER
    // ======================================

    if (driverMarker === null) {

        driverMarker =
            L.marker(
                currentLocation,
                {
                    icon: busIcon
                }
            )
            .addTo(map);


        driverMarker.bindPopup(`

            <strong>${ASSIGNED.bus}</strong>

            <br>

            ${ASSIGNED.route}

            <br>

            Your Actual GPS Location

        `);


    } else {

        driverMarker
            .setLatLng(
                currentLocation
            );

    }



    // ======================================
    // ACCURACY CIRCLE
    // ======================================

    if (accuracyCircle === null) {

        accuracyCircle =
            L.circle(
                currentLocation,
                {

                    radius: accuracy,

                    color: COLORS.sky,

                    fillOpacity: 0.10

                }
            )
            .addTo(map);


    } else {

        accuracyCircle
            .setLatLng(
                currentLocation
            );


        accuracyCircle
            .setRadius(
                accuracy
            );

    }



    // ======================================
    // MOVE MAP TO DRIVER
    // ======================================

    if (!map.hasRealDriverLocation) {
        map.setView(currentLocation, 17);
        map.hasRealDriverLocation = true;
    }



    // ======================================
    // DISPLAY COORDINATES
    // ======================================

    latitudeElement.textContent =
        latitude.toFixed(8);


    longitudeElement.textContent =
        longitude.toFixed(8);


    accuracyElement.textContent =
        Math.round(accuracy) +
        " meters";


    gpsAccuracy.textContent =
        Math.round(accuracy) +
        " m";



    // ======================================
    // DISPLAY SPEED
    // ======================================

    if (speed !== null) {

        speedValue.textContent =
            speed.toFixed(1) +
            " km/h";


        mapSpeed.textContent =
            speed.toFixed(1) +
            " km/h";

    } else {

        speedValue.textContent =
            "0 km/h";


        mapSpeed.textContent =
            "0 km/h";

    }



    // ======================================
    // GPS STATUS
    // ======================================

    gpsStatus.innerHTML = `

        <span></span>

        Online

    `;


    gpsStatus.style.color =
        COLORS.green;


    locationStatus.textContent =
        "Location Sharing Active";


    locationStatus.style.color =
        COLORS.green;


    lastUpdate.textContent =
        "GPS accuracy: " +
        Math.round(accuracy) +
        " meters";


    updateTime.innerHTML = `

        <i class="fas fa-clock"></i>

        Updated:
        ${new Date().toLocaleTimeString()}

    `;



    // ======================================
    // SEND GPS TO MYSQL
    // ======================================

    sendLocationToServer(
        latitude,
        longitude,
        accuracy,
        speed,
        heading
    );



    // ======================================
    // UPDATE DISTANCE TO COMMUTER
    // ======================================

    updateDriverCommuterLine();

}



// ==========================================
// GPS ERROR
// ==========================================

function handleLocationError(error) {

    let message =
        "Unable to get your location.";


    if (
        error.code ===
        error.PERMISSION_DENIED
    ) {

        message =
            "Location permission was denied.";

    }


    else if (
        error.code ===
        error.POSITION_UNAVAILABLE
    ) {

        message =
            "GPS location is unavailable.";

    }


    else if (
        error.code ===
        error.TIMEOUT
    ) {

        message =
            "GPS request timed out.";

    }



    locationStatus.textContent =
        message;


    locationStatus.style.color =
        COLORS.red;


    lastUpdate.textContent =
        "Please check your browser location settings.";


    gpsStatus.innerHTML = `

        <span></span>

        Offline

    `;


    gpsStatus.style.color =
        COLORS.red;

}



// ==========================================
// START LOCATION SHARING
// ==========================================

function startLocationSharing() {


    if (!navigator.geolocation) {

        alert(
            "Your browser does not support GPS location."
        );

        return;

    }



    if (
        !window.isSecureContext &&
        location.hostname !== "localhost" &&
        location.hostname !== "127.0.0.1"
    ) {

        alert(
            "Location sharing requires HTTPS. " +
            "Please use HTTPS when accessing SmartMinibus."
        );

        return;

    }



    locationStatus.textContent =
        "Requesting GPS permission...";


    locationStatus.style.color =
        COLORS.sky;


    lastUpdate.textContent =
        "Please allow location access when your browser asks.";


    gpsStatus.innerHTML = `

        <span></span>

        Requesting...

    `;


    gpsStatus.style.color =
        COLORS.sky;


    locationButton.disabled =
        true;


    locationButton.innerHTML = `

        <i class="fas fa-spinner fa-spin"></i>

        Requesting Location...

    `;



    navigator.geolocation.getCurrentPosition(

        function(position) {

            console.log(
                "Location permission granted."
            );


            handleLocation(
                position
            );


            locationWatcher =
                navigator.geolocation.watchPosition(

                    handleLocation,

                    handleLocationError,

                    {

                        enableHighAccuracy: true,

                        maximumAge: 3000,

                        timeout: 15000

                    }

                );


            sharingLocation =
                true;


            locationButton.disabled =
                false;


            locationButton.innerHTML = `

                <i class="fas fa-location-dot"></i>

                Stop Sharing Location

            `;


            locationButton.classList.remove(
                "stopped"
            );

        },


        function(error) {

            locationButton.disabled =
                false;


            locationButton.innerHTML = `

                <i class="fas fa-location-dot"></i>

                Enable Location Sharing

            `;


            sharingLocation =
                false;


            handleLocationError(
                error
            );

        },


        {

            enableHighAccuracy: true,

            maximumAge: 0,

            timeout: 15000

        }

    );

}



// ==========================================
// STOP LOCATION SHARING
// ==========================================

function stopLocationSharing() {


    if (
        locationWatcher !== null
    ) {

        navigator.geolocation.clearWatch(
            locationWatcher
        );


        locationWatcher =
            null;

    }



    sharingLocation =
        false;

    const stopData = new FormData();
    stopData.append("action", "stop");
    fetch("../api/update_location.php", {
        method: "POST",
        body: stopData,
        credentials: "same-origin",
        cache: "no-store"
    })
    .then(function(response) { return response.json(); })
    .then(function(result) {
        if (!result.success) {
            lastUpdate.textContent = result.message || "Could not stop sharing on the server.";
        }
    })
    .catch(function(error) {
        console.error("Unable to stop GPS sharing:", error);
        lastUpdate.textContent = "Could not confirm that GPS sharing stopped.";
    });


    locationStatus.textContent =
        "Location Sharing Paused";


    locationStatus.style.color =
        COLORS.muted;


    lastUpdate.textContent =
        "Location is not being shared";


    gpsStatus.innerHTML = `

        <span></span>

        Offline

    `;


    gpsStatus.style.color =
        COLORS.muted;


    locationButton.disabled =
        false;


    locationButton.innerHTML = `

        <i class="fas fa-location-dot"></i>

        Enable Location Sharing

    `;


    locationButton.classList.add(
        "stopped"
    );


    updateTime.innerHTML = `

        <i class="fas fa-clock"></i>

        Location not active

    `;

}



// ==========================================
// LOCATION BUTTON
// ==========================================

locationButton.addEventListener(
    "click",
    function() {

        if (sharingLocation) {

            stopLocationSharing();

        }

        else {

            startLocationSharing();

        }

    }
);



// ==========================================================
// REFRESH ASSIGNED TRIP SUMMARY
// ==========================================================
function refreshTripSummary() {
    fetch("../api/get_driver_status.php", {
        credentials: "same-origin",
        cache: "no-store"
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (!data.success) throw new Error(data.message || "Trip status unavailable.");
        const trip = data.trip;
        const active = Boolean(trip);
        document.getElementById("startTripButton").disabled = active;
        document.getElementById("endTripButton").disabled = !active;
        document.getElementById("tripStatus").innerHTML =
            '<span class="status-dot"></span>' + (active ? "Trip departed" : "Trip not started");
        if (!active) {
            ["tripDeparture", "tripDistance", "tripEta", "tripPassengers"].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) el.textContent = "--";
            });
            return;
        }
        const setText = function(id, value) {
            const el = document.getElementById(id);
            if (el) el.textContent = value ?? "--";
        };
        setText("tripDeparture", trip.started_at ? formatTime(trip.started_at) : "--");
        setText("tripDistance", trip.distance_km !== null ? Number(trip.distance_km).toFixed(2) + " km" : "--");
        setText("tripEta", trip.est_minutes !== null ? Number(trip.est_minutes) + " minutes" : "--");
        setText("tripPassengers", trip.seat_status === "full" ? "Full" :
            (trip.seat_status === "available" ? "Seats available" : "Not reported"));
    })
    .catch(function(error) {
        console.debug("Trip summary unavailable:", error);
    });
}
refreshTripSummary();
setInterval(refreshTripSummary, 10000);

function postDriverAction(action, values) {
    const payload = new FormData();
    payload.append("action", action);
    Object.keys(values || {}).forEach(function(key) { payload.append(key, values[key]); });
    return fetch("../api/driver_action.php", {
        method: "POST",
        body: payload,
        credentials: "same-origin",
        cache: "no-store"
    }).then(function(response) {
        return response.json().then(function(result) {
            if (!response.ok || !result.success) throw new Error(result.message || "Request failed.");
            return result;
        });
    });
}

document.getElementById("startTripButton").addEventListener("click", function() {
    const button = this;
    const message = document.getElementById("tripActionMessage");
    button.disabled = true;
    message.textContent = "Starting trip...";
    postDriverAction("trip_start").then(function(result) {
        message.textContent = result.message;
        refreshTripSummary();
    }).catch(function(error) {
        message.textContent = error.message;
        button.disabled = false;
    });
});

document.getElementById("endTripButton").addEventListener("click", function() {
    if (!window.confirm("Complete this trip? Commuters will see it as completed.")) return;
    const button = this;
    const message = document.getElementById("tripActionMessage");
    button.disabled = true;
    message.textContent = "Completing trip...";
    postDriverAction("trip_end").then(function(result) {
        message.textContent = result.message;
        refreshTripSummary();
    }).catch(function(error) {
        message.textContent = error.message;
        button.disabled = false;
    });
});

function updateSeatStatus(seatStatus) {
    const message = document.getElementById("seatStatusMessage");
    message.textContent = "Updating passenger availability...";
    postDriverAction("seat_status", { seat_status: seatStatus }).then(function(result) {
        message.textContent = result.message;
    }).catch(function(error) {
        message.textContent = error.message;
    });
}

document.getElementById("seatsAvailableButton").addEventListener("click", function() {
    updateSeatStatus("available");
});
document.getElementById("busFullButton").addEventListener("click", function() {
    updateSeatStatus("full");
});

function loadDriverNotifications() {
    fetch("../api/communication.php?action=notification_list", {
        credentials: "same-origin",
        cache: "no-store"
    }).then(function(response) { return response.json(); })
    .then(function(result) {
        if (!result.ok) throw new Error(result.message || "Could not load notifications.");
        const list = document.getElementById("driverNotifications");
        if (!result.data.length) {
            list.innerHTML = "<p>No admin notifications yet.</p>";
            return;
        }
        list.innerHTML = result.data.map(function(item) {
            const safe = function(value) {
                const node = document.createElement("span");
                node.textContent = value || "";
                return node.innerHTML;
            };
            return '<article class="notification-item"><div class="notification-icon blue"><i class="fas fa-bell"></i></div><div><strong>' +
                safe(item.title) + '</strong><p>' + safe(item.message) + '</p><span>' +
                safe(item.sender_name || "SmartMinibus") + " · " + safe(item.created_at) +
                (Number(item.is_read) ? " · Read" : " · New") + '</span></div></article>';
        }).join("");
    }).catch(function(error) {
        console.error("Driver notification error:", error);
        document.getElementById("driverNotifications").textContent =
            "Unable to load notifications. Please refresh the page.";
    });
}
loadDriverNotifications();
setInterval(function() {
    if (!document.hidden) loadDriverNotifications();
}, 30000);


// ==========================================================
// FORMAT TIME
// ==========================================================

function formatTime(dateValue) {

    if (!dateValue) {

        return "Unknown";

    }


    const date =
        new Date(
            dateValue.replace(
                " ",
                "T"
            )
        );


    if (
        isNaN(
            date.getTime()
        )
    ) {

        return dateValue;

    }


    return date.toLocaleTimeString(
        [],
        {

            hour: "2-digit",

            minute: "2-digit",

            second: "2-digit"

        }
    );

}



// ==========================================================
// ESCAPE HTML
// ==========================================================

function escapeHtml(text) {

    const div =
        document.createElement(
            "div"
        );


    div.textContent =
        text;


    return div.innerHTML;

}



// ==========================================================
// START DRIVER GPS
// ==========================================================

// IMPORTANT:
// Driver GPS does NOT start automatically.

// The driver must click:
// "Enable Location Sharing"



// startLocationSharing();

</script>


</body>

</html>