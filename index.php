<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartMinibus | Real-Time Tracking</title>
    <link rel="stylesheet" href="css/index.css?v=<?= (int)@filemtime(__DIR__ . '/css/index.css') ?>">
    <link rel="stylesheet" href="css/app-polish.css?v=<?= (int)@filemtime(__DIR__ . '/css/app-polish.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>

<body>

<!--  NAVBAR  -->
<header class="navbar">
    <a class="brand-section" href="index.php" aria-label="SmartMinibus Home">
        <div class="brand-logo">
            <img src="images/logo.png" alt="">
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

    <button class="mobile-nav-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="mainNavigation">
        <span></span>
        <span></span>
        <span></span>
    </button>

    <nav id="mainNavigation" aria-label="Main navigation">
        <a href="index.php" class="active">Home</a>
        <a href="#features">Features</a>
        <a href="#about">About</a>
        <a href="#contact">Contact</a>
        <a href="login.php" class="login-btn">Login</a>
    </nav>

</header>


<!--  HERO  -->
<section class="hero">

    <div class="hero-text">

        <span class="tagline">SMART • FAST • CONVENIENT</span>

        <h1>
            Track Your <span>Minibus</span><br>
            in Real Time
        </h1>

        <p>
            SmartMinibus helps passengers easily check the current
            location and estimated arrival time of minibuses.
        </p>

        <div class="hero-buttons">
            <a href="login.php" class="primary-btn">
                Get Started
            </a>

            <a href="#features" class="secondary-btn">
                Learn More
            </a>
        </div>

    </div>


    <!-- RIGHT SIDE ILLUSTRATION -->
    <div class="hero-visual">

        <div class="map-card">

            <div class="map-header">
                <span>Live Minibus Tracking</span>
                <span class="live">● LIVE</span>
            </div>

            <div class="map-area">

                <!-- roads -->
                <div class="road road1"></div>
                <div class="road road2"></div>
                <div class="road road3"></div>

                <!-- route -->
                <div class="route-line"></div>

                <!-- bus -->
                <div class="bus-marker">
                    🚌
                </div>

                <!-- location -->
                <div class="location-marker">
                    <span></span>
                </div>

            </div>

            <div class="eta-card">

                <div>
                    <small>Next Arrival</small>
                    <strong>8 min</strong>
                </div>

                <div>
                    <small>Speed</small>
                    <strong>38 km/h</strong>
                </div>

                <div>
                    <small>Status</small>
                    <strong class="on-route">On Route</strong>
                </div>

            </div>

        </div>

    </div>

</section>


<!--  FEATURES  -->
<section class="features" id="features">

    <div class="section-title">

        <span>WHAT WE OFFER</span>

        <h2>Smart Transportation Made Easier</h2>

        <p>
            SmartMinibus provides useful information to passengers,
            drivers, and administrators.
        </p>

    </div>


    <div class="feature-container">

        <div class="feature-card">

            <div class="feature-icon">📍</div>

            <h3>Live Tracking</h3>

            <p>
                View the current location of active minibuses
                using real-time GPS information.
            </p>

        </div>


        <div class="feature-card">

            <div class="feature-icon">⏱️</div>

            <h3>ETA Prediction</h3>

            <p>
                Check the estimated arrival time of a minibus
                before going to your destination.
            </p>

        </div>


        <div class="feature-card">

            <div class="feature-icon">🔔</div>

            <h3>Notifications</h3>

            <p>
                Receive updates about minibus arrivals,
                delays, and route information.
            </p>

        </div>

    </div>

</section>


<!--  ABOUT  -->
<section class="about" id="about">

    <div class="about-text">

        <span>ABOUT SMARTMINIBUS</span>

        <h2>Making Minibus Transportation Smarter</h2>

        <p>
            SmartMinibus is an IoT-based tracking system designed
            to help passengers monitor minibus locations and
            estimated arrival times.
        </p>

        <p>
            The system also provides tools for drivers and
            administrators to manage routes, monitor vehicles,
            and provide transportation updates.
        </p>

    </div>

    <div class="about-box">

        <div class="about-stat">
            <strong>GPS</strong>
            <span>Real-Time Location</span>
        </div>

        <div class="about-stat">
            <strong>ETA</strong>
            <span>Arrival Prediction</span>
        </div>

        <div class="about-stat">
            <strong>IoT</strong>
            <span>Connected System</span>
        </div>

    </div>

</section>


<!--  CONTACT  -->
<section class="contact" id="contact">

    <div class="section-title">

        <span>CONTACT</span>

        <h2>Need More Information?</h2>

        <p>
            SmartMinibus is designed to make minibus transportation
            information easier to access.
        </p>

        <a href="login.php" class="primary-btn">
            Login to SmartMinibus
        </a>

    </div>

</section>


<!--  FOOTER  -->
<footer class="site-footer">
    <div class="footer-content">

        <div class="footer-brand">
            <div class="footer-brand-title">
                <img src="images/logo.png" alt="SmartMinibus Logo">
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
                <a href="index.php">Home</a>
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

    <div class="footer-bottom">© 2026 SmartMinibus. All rights reserved.</div>
</footer>

<script>
    <!--controls the mobile navigation/header menu (HAMBURGER MENU)-->
    const navbar = document.querySelector('.navbar');
    const navToggle = document.querySelector('.mobile-nav-toggle'); //finds the element with the class mobile-nav-toggle.
    const navLinks = document.querySelectorAll('.navbar nav a'); //finds all <a> links

    if (navToggle && navbar) { //Only run the following code if both the hamburger button and navbar actually exis.
        navToggle.addEventListener('click', function(event) {
            event.stopPropagation();
            const isOpen = navbar.classList.toggle('open');
            navToggle.setAttribute('aria-expanded', String(isOpen)); // changes the button's aria-expanded attribute
        });

        document.addEventListener('click', function(event) { // execute when the hamburger button clicked
            const clickedInsideNavbar = navbar.contains(event.target);

            if (!clickedInsideNavbar && navbar.classList.contains('open')) {
                navbar.classList.remove('open');
                navToggle.setAttribute('aria-expanded', 'false');
            }
        });

        navLinks.forEach(function(link) {
            link.addEventListener('click', function() { 
                navbar.classList.remove('open');
                navToggle.setAttribute('aria-expanded', 'false');
            });
        });
    }
</script>

</body>
</html>