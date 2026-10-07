
Register · PHP
<?php
 
session_start();
 
require_once __DIR__ . "/config/database.php";
 
$error = "";
$success = "";
 
// Keep entered values after a validation error
$old = [
    "first_name" => "",
    "last_name"  => "",
    "email"      => "",
    "phone"      => "",
    "role"       => "commuter",
];
 
if ($_SERVER["REQUEST_METHOD"] === "POST") {
 
    $first_name       = trim($_POST["first_name"] ?? "");
    $last_name        = trim($_POST["last_name"] ?? "");
    $email            = strtolower(trim($_POST["email"] ?? ""));
    $phone            = trim($_POST["phone"] ?? "");
    $password         = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";
    $terms            = isset($_POST["terms"]);
    $role             = $_POST["role"] ?? "commuter";
 
    $old = [
        "first_name" => $first_name,
        "last_name"  => $last_name,
        "email"      => $email,
        "phone"      => $phone,
        "role"       => in_array($role, ["commuter", "driver"], true) ? $role : "commuter",
    ];
 
    // ---------- Phone normalisation (PH mobile) ----------
    // Accepts: 09XXXXXXXXX, +639XXXXXXXXX, 639XXXXXXXXX, 9XXXXXXXXX
    $phone_clean = preg_replace('/[\s\-\(\)]/', '', $phone);
    if (preg_match('/^(?:\+63|63)(9\d{9})$/', $phone_clean, $m)) {
        $phone_clean = "0" . $m[1];
    } elseif (preg_match('/^9\d{9}$/', $phone_clean)) {
        $phone_clean = "0" . $phone_clean;
    }
 
    // ---------- Gmail / Google email check ----------
    $email_parts  = explode("@", $email);
    $email_local  = $email_parts[0] ?? "";
    $email_domain = $email_parts[1] ?? "";
    $is_google_email = in_array($email_domain, ["gmail.com", "googlemail.com"], true);
    // Gmail usernames: 6-30 chars, letters, numbers, periods only
    $valid_gmail_local = preg_match('/^[a-z0-9][a-z0-9.]{4,28}[a-z0-9]$/', $email_local)
                         && strpos($email_local, "..") === false;
 
    // ================= VALIDATION =================
 
    if (
        $first_name === "" ||
        $last_name === "" ||
        $email === "" ||
        $phone === "" ||
        $password === "" ||
        $confirm_password === ""
    ) {
 
        $error = "Please complete all fields.";
 
    } elseif (!preg_match("/^[\p{L}][\p{L}\s.'\-]{0,49}$/u", $first_name)) {
        $error = "Please enter a valid first name (letters only).";
 
    } elseif (!preg_match("/^[\p{L}][\p{L}\s.'\-]{0,49}$/u", $last_name)) {
        $error = "Please enter a valid last name (letters only).";
 
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
 
    } elseif (!$is_google_email) {
        $error = "Please use a valid Gmail address (example@gmail.com).";
 
    } elseif (!$valid_gmail_local) {
        $error = "That Gmail address is not valid. Gmail usernames are 6-30 characters (letters, numbers, periods).";
 
    } elseif (!preg_match('/^09\d{9}$/', $phone_clean)) {
        $error = "Please enter a valid Philippine mobile number (e.g. 09123456789).";
 
    } elseif (strlen($password) < 8 || !preg_match('/\d/', $password)) {
        $error = "Password must be at least 8 characters and include at least one number.";
 
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
 
    } elseif (!$terms) {
        $error = "Please agree to the Terms of Service and Privacy Policy.";
 
    } elseif (!in_array($role, ["commuter", "driver"], true)) {
        $error = "Invalid account type.";
 
    } else {
 
        // ================= CHECK EMAIL / PHONE =================
 
        $check = $conn->prepare(
            "SELECT user_id, email_verified FROM users WHERE email = ? LIMIT 1"
        );
        $check->execute([$email]);
        $existing = $check->fetch();
        $check->closeCursor();
 
        $phoneTaken = false;
        $pc = $conn->prepare(
            "SELECT user_id FROM users WHERE phone = ? AND phone_verified = 1 LIMIT 1"
        );
        $pc->execute([$phone_clean]);
        $phoneTaken = $pc->fetch() !== false;
        $pc->closeCursor();
 
        if ($existing && (int)$existing["email_verified"] === 1) {
            $error = "This email address is already registered.";
 
        } elseif ($phoneTaken) {
            $error = "This phone number is already registered.";
 
        } else {
 
            // ================= CREATE ACCOUNT (unverified) =================
 
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $status = "active";
 
            if ($existing) {
                // Earlier sign-up with this email was never verified: replace it
                $user_id = (int) $existing["user_id"];
 
                $stmt = $conn->prepare(
                    "UPDATE users
                     SET first_name = ?, last_name = ?, password = ?, role = ?,
                         phone = ?, status = ?, email_verified = 0, phone_verified = 0
                     WHERE user_id = ?"
                );
                $stmt->execute([
                    $first_name, $last_name, $hashed_password, $role,
                    $phone_clean, $status, $user_id,
                ]);
                $ok = true;
                $stmt->closeCursor();

                $clr = $conn->prepare("DELETE FROM verification_codes WHERE user_id = ?");
                $clr->execute([$user_id]);
                $clr->closeCursor();
 
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO users
                        (first_name, last_name, email, password, role, phone, status,
                         email_verified, phone_verified)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0)"
                );
                $stmt->execute([
                    $first_name, $last_name, $email, $hashed_password,
                    $role, $phone_clean, $status,
                ]);
                $ok = $stmt->rowCount() === 1;
                $user_id = (int)$conn->lastInsertId();
                $stmt->closeCursor();
            }
 
            if ($ok) {
                // verify.php sends the email + SMS codes
                $_SESSION["verify_user_id"] = $user_id;
                header("Location: verify.php");
                exit;
            }
 
            $error = "Something went wrong while creating your account.";
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
    <title>Create Account | SmartMinibus</title>
    <link rel="stylesheet" href="css/register.css?v=<?= (int)@filemtime(__DIR__ . '/css/register.css') ?>">
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

        <a href="login.php" class="header-link">Login</a>
    </header>



    <div class="register-page">


        <!-- ================= RIGHT SIDE ================= -->

        <section class="register-form-section">

            <div class="register-container">
                <div class="register-heading">
                    <h2>Create Account</h2>
                    <p>
                        Fill in the information below to register.
                    </p>

                </div>


                <?php if ($error !== ""): ?>

                    <div class="message error">
                        <?php echo htmlspecialchars($error); ?>
                    </div>

                <?php endif; ?>


                <?php if ($success !== ""): ?>

                    <div class="message success">
                        <?php echo htmlspecialchars($success); ?>
                        <br>

                        <a href="login.php">
                            Go to Login
                        </a>
                    </div>

                <?php endif; ?>

                <?php if ($success === ""): ?>

                <form method="POST" action="register.php">


                    <!-- ================= ROLE TABS ================= -->

                    <div class="role-tabs" role="tablist" aria-label="Register as">

                        <button
                            type="button"
                            class="role-tab active"
                            role="tab"
                            aria-selected="true"
                            data-role="commuter">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="12" cy="8" r="4"></circle>
                                <path d="M4 21c0-4.2 3.5-7 8-7s8 2.8 8 7"></path>
                            </svg>
                            <span>Commuter</span>
                        </button>

                        <button
                            type="button"
                            class="role-tab"
                            role="tab"
                            aria-selected="false"
                            data-role="driver">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <circle
                                    cx="12"
                                    cy="12"
                                    r="9"
                                    stroke="currentColor"
                                    stroke-width="1.8"/>
                                <circle
                                    cx="12"
                                    cy="12"
                                    r="2.5"
                                    fill="currentColor"/>
                                <path
                                    d="M12 4v3M12 17v3M4 12h3M17 12h3"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"/>
                            </svg>
                            <span>Driver</span>
                        </button>

                    </div>

                    <!-- Hidden input keeps the selected role available to PHP -->
                    <input type="hidden" name="role" id="selected-role" value="commuter">

                    <!-- ================= NAME ================= -->

                    <div class="form-group">
                        <label for="first_name">
                            First name
                        </label>

                        <input
                            type="text"
                            id="first_name"
                            name="first_name"
                            placeholder="Enter your first name"
                            required>

                    </div>

                    <div class="form-group">
                        <label for="last_name">
                            Last name
                        </label>

                        <input
                            type="text"
                            id="last_name"
                            name="last_name"
                            placeholder="Enter your last name"
                            required>

                    </div>


                    <!-- ================= EMAIL ================= -->

                    <div class="form-group">

                        <label for="email">
                            Email Address
                        </label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="Enter your email"
                            required>

                    </div>


                    <!-- ================= PHONE ================= -->

                    <div class="form-group">

                        <label for="phone">
                            Phone Number
                        </label>

                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            placeholder="09XXXXXXXXX">

                    </div>


                    <!-- ================= PASSWORDS ================= -->

                    <div class="password-row">

                        <div class="form-group password-group">

                            <label for="password">Password</label>

                            <div class="password-input-wrap">
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    placeholder="At least 8 characters"
                                    minlength="8"
                                    required
                                    autocomplete="new-password">

                                <button
                                    type="button"
                                    class="password-toggle"
                                    data-target="password"
                                    aria-label="Show password">
                                    Show
                                </button>
                            </div>

                        </div>


                        <div class="form-group password-group">

                            <label for="confirm_password">Confirm Password</label>

                            <div class="password-input-wrap">
                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    placeholder="Re-enter password"
                                    minlength="8"
                                    required
                                    autocomplete="new-password">

                                <button
                                    type="button"
                                    class="password-toggle"
                                    data-target="confirm_password"
                                    aria-label="Show confirm password">
                                    Show
                                </button>
                            </div>

                        </div>

                    </div>

                    <p class="password-hint">
                        Use at least 8 characters, including a number.
                    </p>


                    <!-- ================= TERMS ================= -->

                    <div class="terms-group">
                        <label class="terms-label" for="terms">
                            <input
                                type="checkbox"
                                id="terms"
                                name="terms"
                                value="1"
                                required >
                            <span>
                                I agree to the SmartMinibus
                                <a href="#" onclick="return false;">Terms of Service</a>
                                and
                                <a href="#" onclick="return false;">Privacy Policy</a>.
                            </span>
                        </label>
                    </div>


                    <button
                        type="submit"
                        class="register-btn">
                        Create Account
                    </button>


                </form>

                <?php endif; ?>

                <div class="login-link">
                    Already have an account?
                    <a href="login.php">
                        Login here
                    </a>
                </div>
            </div>

        </section>

    </div>


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
                    <li><a href="About.html">About</a></li>
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
            document.addEventListener("DOMContentLoaded", function () {
                const form = document.querySelector("form[method=\"POST\"]");
                const roleTabs = document.querySelectorAll(".role-tab");
                const roleInput = document.getElementById("selected-role");

                if (!form || !roleInput || roleTabs.length === 0) return;

                roleTabs.forEach(function (tab) {
                    tab.addEventListener("click", function () {
                        roleTabs.forEach(function (item) {
                            item.classList.remove("active");
                            item.setAttribute("aria-selected", "false");
                        });

                        this.classList.add("active");
                        this.setAttribute("aria-selected", "true");
                        roleInput.value = this.dataset.role;
                    });
                });

                // Show / hide password controls
                document.querySelectorAll(".password-toggle").forEach(function (toggle) {
                    toggle.addEventListener("click", function () {
                        const target = document.getElementById(this.dataset.target);
                        if (!target) return;

                        const showing = target.type === "text";
                        target.type = showing ? "password" : "text";
                        this.textContent = showing ? "Show" : "Hide";
                        this.setAttribute(
                            "aria-label",
                            (showing ? "Show " : "Hide ") +
                            (target.id === "password" ? "password" : "confirm password")
                        );
                    });
                });
            });
        </script>

</body>

</html>