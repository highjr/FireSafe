<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_log("accounts.php: Step 1 - Script started at " . microtime(true));
$request_uri = $_SERVER['REQUEST_URI'];
$request_id = md5($request_uri . microtime(true));
$_SESSION['current_request_id'] = $request_id;
unset($_SESSION['fixed_bar_rendered_' . $request_id]);
error_log("accounts.php: Step 4 - Request ID set: " . $request_id);
if (isset($_SESSION['page_rendered_' . $request_id]) && $_SESSION['page_rendered_' . $request_id]) {
    error_log("accounts.php: Step 5 - Already rendered for request_id: " . $request_id . ", exiting");
    session_write_close();
    exit;
}

error_log("accounts.php: Step 6 - Proceeding with script");
require_once 'config.php';
error_log("accounts.php: Step 7 - Config included at " . microtime(true));

if (!isset($_SESSION['user_id'])) {
    error_log("accounts.php: Step 8 - No user logged in, redirecting to index.php");
    session_write_close();
    header('Location: index.php');
    exit;
}
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
error_log("accounts.php: Step 9 - User logged in, ID: $user_id, Role: $role");

$category_id = 5; // Fixed for Accounts (previously User Balances)
$category_name = "Accounts";

$cache_buster = time();
$_SESSION['page_rendered_' . $request_id] = true;
error_log("accounts.php: Step 35 - Page marked as rendered");
session_write_close();
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FireSafe - Accounts</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        if (!window.location.search.includes('t=')) {
            window.location.href = window.location.pathname + '?id=<?php echo $category_id; ?>&t=<?php echo $cache_buster; ?>';
        }
    </script>

<style>
    .main-container { 
        display: flex; 
        flex-wrap: wrap;
        width: 100%; 
        padding-top: 36px; 
    }
    .content-container { 
        padding-top: 50px; 
        width: 100%;
        position: relative;
    }
    .sidebar-offset { margin-left: 256px; }
    aside { 
        position: fixed; 
        top: 0; 
        left: 0; 
        width: 256px; 
        height: 100vh; 
        z-index: 30; 
    }
    .content-wrapper { 
        flex: 1; 
        margin-left: 266px;
        min-width: 0;
    }
    .container {
        max-width: none;
        width: 100%;
    }
    @media (max-width: 768px) {
        aside { 
            position: relative;
            width: 100%; 
            height: auto; 
            z-index: 30; 
        }
        .content-wrapper { 
            margin-left: 0;
            width: 100%; 
        }
        .sidebar-offset { 
            margin-left: 0;
        }
    }
</style>

</head>
<body class="bg-gray-100 min-h-screen">
<div class="main-container">
    <?php 
    session_start();
    if (!isset($_SESSION['sidebar_rendered_' . $request_id])) {
        include 'sidebar.php';
        $_SESSION['sidebar_rendered_' . $request_id] = true;
        error_log("accounts.php: Sidebar rendered for request_id: " . $request_id);
    } else {
        error_log("accounts.php: Sidebar already rendered for request_id: " . $request_id . ", skipping");
    }
    session_write_close();
    ?>

    <div class="content-wrapper">
        <nav class="bg-blue-600 text-white p-4 fixed top-0 left-0 right-0 z-40">
            <div class="flex justify-between items-center pl-4">
                <h1 class="text-3xl font-bold">FireSafe</h1>
                <div>
                    <span class="mr-4">Welcome, <?php echo htmlspecialchars($role); ?>!</span>
                    <a href="logout.php" class="text-red-300 hover:text-red-100">Log Out</a>
                </div>
            </div>
        </nav>
        <div class="container mx-auto py-8">
            <h2 class="text-3xl font-bold mb-6"><?php echo htmlspecialchars($category_name); ?></h2>
            <p class="text-gray-600">This section is under development. Check back soon for updates!</p>
            <a href="home.php?t=<?php echo $cache_buster; ?>" class="mt-4 inline-block text-blue-600 hover:underline">Back to Home</a>
        </div>
    </div>
</div>
</body>
</html>
<?php
error_log("accounts.php: Step 36 - Script completed at " . microtime(true));
?>
