<?php

session_start();

/*
|--------------------------------------------------------------------------
| LOGOUT CONFIRMATION
|--------------------------------------------------------------------------
| Show a confirmation page first.
| The session will only be destroyed after the admin confirms logout.
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (isset($_POST["confirm_logout"]) && $_POST["confirm_logout"] === "yes") {

        // Remove all session data
        $_SESSION = [];

        // Remove the session cookie if cookies are being used
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                "",
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        // Destroy the current session
        session_destroy();

        // Return to login page
        header("Location: login.php");
        exit;
    }

    // If cancelled, return to the dashboard
    header("Location: dashboard.php");
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Confirm Logout - TRIKE-Q</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .logout-card {
            width: 100%;
            max-width: 430px;
            background: #ffffff;
            border-radius: 14px;
            padding: 35px 30px;
            text-align: center;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.10);
        }

        .logo {
            color: #f97316;
            font-size: 30px;
            font-weight: 800;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }

        .subtitle {
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 1px;
            margin-bottom: 28px;
        }

        .logout-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: #fff7ed;
            color: #f97316;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            font-weight: bold;
        }

        h2 {
            color: #1f2937;
            font-size: 22px;
            margin-bottom: 10px;
        }

        .message {
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 26px;
        }

        .actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .btn {
            flex: 1;
            border: none;
            border-radius: 8px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s ease;
        }

        .btn-cancel {
            background: #f1f5f9;
            color: #334155;
        }

        .btn-cancel:hover {
            background: #e2e8f0;
        }

        .btn-logout {
            background: #f97316;
            color: #ffffff;
        }

        .btn-logout:hover {
            background: #ea580c;
        }

        @media (max-width: 480px) {
            .logout-card {
                padding: 30px 22px;
            }

            .actions {
                flex-direction: column-reverse;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="logout-card">

    <div class="logo">TRIKE-Q</div>
    <div class="subtitle">TMU ADMIN SYSTEM</div>

    <div class="logout-icon">
        ↪
    </div>

    <h2>Confirm Logout</h2>

    <p class="message">
        Are you sure you want to log out of the
        TRIKE-Q TMU Admin System?
    </p>

    <form method="POST" action="logout.php">

        <div class="actions">

            <a href="dashboard.php" class="btn btn-cancel">
                Cancel
            </a>

            <button
                type="submit"
                name="confirm_logout"
                value="yes"
                class="btn btn-logout"
            >
                Yes, Log Out
            </button>

        </div>

    </form>

</div>

</body>
</html>