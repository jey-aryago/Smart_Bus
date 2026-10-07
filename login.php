<?php

session_start();

require_once __DIR__ . "/config/database.php";

// If already logged in, redirect to the correct dashboard
if (isset($_SESSION['user_id'])) {

    if ($_SESSION['role'] === 'commuter') {
        header("Location: commuter/dashboard.php");
        exit;
    }

    if ($_SESSION['role'] === 'driver') {
        header("Location: driver/dashboard.php");
        exit;
    }

    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/dashboard.php");
        exit;
    }
}


$error = "";

//  LOGIN PROCESS 

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $role = $_POST["role"] ?? "";

    if ($email === "" || $password === "" || $role === "") {

        $error = "Please complete all fields.";

    } else {

        $sql = "SELECT user_id, first_name, last_name, email, password, role, status
                FROM users
                WHERE email = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);

        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user !== false) {


            // Check account status
            if ($user["status"] !== "active") {

                $error = "Your account is not active.";

            }

            // Check password
            elseif (!password_verify($password, $user["password"])) {

                $error = "Incorrect email or password.";

            }

            // Check selected role
            elseif ($user["role"] !== $role) {

                $error = "The selected account type does not match your account.";

            }

            else {

                // Save login information
                $_SESSION["user_id"] = $user["user_id"];
                $_SESSION["first_name"] = $user["first_name"];
                $_SESSION["last_name"] = $user["last_name"];
                $_SESSION["email"] = $user["email"];
                $_SESSION["full_name"] = trim($user["first_name"] . " " . $user["last_name"]);
                $_SESSION["role"] = $user["role"];


                // Redirect based on role
                if ($user["role"] === "commuter") {

                    header("Location: commuter/dashboard.php");
                    exit;

                } elseif ($user["role"] === "driver") {

                    header("Location: driver/dashboard.php");
                    exit;

                } elseif ($user["role"] === "admin") {

                    header("Location: admin/dashboard.php");
                    exit;
                }
            }

        } else {

            $error = "Incorrect email or password.";
        }

        $stmt->closeCursor();
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | SmartMinibus</title>
    <link rel="stylesheet" href="css/login.css?v=<?= (int)@filemtime(__DIR__ . '/css/login.css') ?>">
    <link rel="stylesheet" href="css/app-polish.css?v=<?= (int)@filemtime(__DIR__ . '/css/app-polish.css') ?>">
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

</head>


<body>

<header class="top-header">
    <a href="index.php" class="brand-section" aria-label="SmartMinibus Home">
        <div class="brand-logo">
            <img src="images/logo.png" alt="SmartMinibus Logo">
        </div>
        <div class="brand-copy">
            <div class="brand-name"><span>Smart</span><strong>Minibus</strong></div>
            <div class="brand-tagline">Smart Travel. Smarter Commute</div>
        </div>
    </a>

    <a href="index.php" class="header-link">Back to Home</a>
</header>

<main class="login-main">

    <section class="visual-panel" aria-label="SmartMinibus tracking">
        <img src="images/pic1.png" alt="SmartMinibus GPS tracking" class="visual-image">
    </section>

    <section class="form-panel">
        <div class="login-card">

            <h1>WELCOME BACK!</h1>
            <p class="subtitle">Log in to access the SmartMinibus system</p>

            <?php if ($error !== ""): ?>
                <div class="error-message" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="role-tabs" role="tablist" aria-label="Login as">
                <button type="button" class="role-tab active" role="tab"
                        aria-selected="true" data-role="commuter">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="8" r="4"></circle>
                        <path d="M4 21c0-4.2 3.5-7 8-7s8 2.8 8 7"></path>
                    </svg>
                    <span>Commuter</span>
                </button>

                <button type="button" class="role-tab" role="tab"
                        aria-selected="false" data-role="driver">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/>
                        <circle cx="12" cy="12" r="2.5" fill="currentColor"/>
                        <path d="M12 4v3M12 17v3M4 12h3M17 12h3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                    <span>Driver</span>
                </button>

                <button type="button" class="role-tab" role="tab"
                        aria-selected="false" data-role="admin">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 1.55 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51 1z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5"/>
                    </svg>
                    <span>Administrator</span>
                </button>
            </div>

            <form id="loginForm" method="POST" action="">
                <input type="hidden" name="role" id="role" value="commuter">

                <label for="email">Email Address</label>
                <input type="email" id="email" name="email"
                       placeholder="Magalpoc@gmail.com" autocomplete="email" required>

                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       placeholder="Password" autocomplete="current-password" required>

                <div class="remember-row">
                    <label class="checkbox">
                        <input type="checkbox" id="remember" name="remember">
                        <span>Remember Me</span>
                    </label>
                </div>

                <button type="submit" class="btn-login">LOGIN</button>
            </form>

            <p class="or-divider">or</p>

            <p class="signup-line">
                Don’t have an account?
                <a href="register.php">Sign Up</a>
            </p>

        </div>
    </section>

</main>

<footer class="site-footer">

    <div class="footer-grid">

        <div class="footer-brand">
            <div class="footer-brand-title">
                <img src="images/logo.png" alt="SmartMinibus Logo" class="footer-logo">
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
                <li><a href="index.php">Home</a></li>
                <li><a href="#">Notifications</a></li>
                <li><a href="#">About</a></li>
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
                <a href="https://www.facebook.com/YOUR_PAGE" target="_blank" rel="noopener noreferrer" aria-label="Facebook">f</a>
                <a href="https://x.com/YOUR_PAGE" target="_blank" rel="noopener noreferrer" aria-label="X">𝕏</a>
                <a href="https://www.instagram.com/YOUR_PAGE" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="2"/>
                        <circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/>
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

    <script>
    const roleTabs = document.querySelectorAll(".role-tab");
    const roleInput = document.getElementById("role");

    roleTabs.forEach(tab => {
        tab.addEventListener("click", function () {
            roleTabs.forEach(item => {
                item.classList.remove("active");
                item.setAttribute("aria-selected", "false");
            });

            this.classList.add("active");
            this.setAttribute("aria-selected", "true");
            roleInput.value = this.dataset.role;
        });
    });
    </script>


</body>

</html>