<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_log("home.php: Step 1 - Script started at " . microtime(true));
$request_uri = $_SERVER['REQUEST_URI'];
$request_id = md5($request_uri . microtime(true));
$_SESSION['current_request_id'] = $request_id;
error_log("home.php: Step 4 - Request ID set: " . $request_id);
if (isset($_SESSION['page_rendered_' . $request_id]) && $_SESSION['page_rendered_' . $request_id]) {
    error_log("home.php: Step 5 - Already rendered for request_id: " . $request_id . ", exiting");
    session_write_close();
    exit;
}

error_log("home.php: Step 6 - Proceeding with script");
require_once 'config.php';
error_log("home.php: Step 7 - Config included at " . microtime(true));

if (!isset($_SESSION['user_id'])) {
    error_log("home.php: Step 8 - No user logged in, redirecting to index.php");
    session_write_close();
    header('Location: index.php');
    exit;
}
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
error_log("home.php: Step 9 - User logged in, ID: $user_id, Role: $role");

// Fetch categories (excluding those we’ll manually reorder and 11, 12, replacing with 13)
$category_stmt = $mysqli->prepare('SELECT id, name FROM categories WHERE id NOT IN (1, 8, 5, 11, 12) ORDER BY id');
$category_stmt->execute();
$result = $category_stmt->get_result();
$categories = $result->fetch_all(MYSQLI_ASSOC);
$category_stmt->close();

// Define the reordered menu (matching sidebar.php order, adding Finances)
$ordered_categories = [
    ['id' => 0, 'name' => 'Home', 'url' => 'home.php'],
    ['id' => 1, 'name' => 'User Profile', 'url' => 'category.php'],
    ['id' => 8, 'name' => 'Roster', 'url' => 'category.php'],
    ['id' => 5, 'name' => 'Inventory', 'url' => 'category.php'],
    ['id' => 13, 'name' => 'Finances', 'url' => 'category.php'] // Added Finances with ID 13
];

// Append the remaining categories
foreach ($categories as $category) {
    $ordered_categories[] = ['id' => $category['id'], 'name' => $category['name'], 'url' => 'category.php'];
}

$cache_buster = time();
$_SESSION['page_rendered_' . $request_id] = true;
error_log("home.php: Step 35 - Page marked as rendered");
session_write_close();
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FireSafe - Home</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        if (!window.location.search.includes('t=')) {
            window.location.href = window.location.pathname + '?t=<?php echo $cache_buster; ?>';
        }
    </script>

<style>
    .main-container { 
        display: flex; 
        flex-wrap: wrap;
        width: 100%; 
        padding-top: 36px; 
    }
    .content-wrapper { 
        flex: 1; 
        margin-left: 0; /* No sidebar, so no offset needed */
        min-width: 0;
    }
    .container {
        max-width: none;
        width: 100%;
    }
    .category-button {
        display: block; /* Ensure buttons take full width of their grid cell */
        background-color: #2563eb;
        color: white;
        padding: 12px;
        border-radius: 5px;
        text-align: center;
        text-decoration: none;
        font-size: 18px;
        transition: background-color 0.3s ease;
        width: 100%; /* Ensure all buttons are the same width */
        box-sizing: border-box; /* Include padding in width calculation */
    }
    .category-button:hover {
        background-color: #1d4ed8;
    }
    .button-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr)); /* 4 columns, equal width */
        gap: 16px; /* Space between buttons */
        justify-content: center; /* Center the grid */
        max-width: 1200px; /* Limit grid width for larger screens */
        margin: 0 auto; /* Center the grid horizontally */
    }
    @media (max-width: 1024px) {
        .button-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr)); /* 3 columns on medium screens */
        }
    }
    @media (max-width: 768px) {
        .button-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)); /* 2 columns on smaller screens */
        }
    }
    @media (max-width: 480px) {
        .button-grid {
            grid-template-columns: 1fr; /* 1 column on very small screens */
        }
    }
</style>
</head>
<body class="bg-gray-100 min-h-screen">
<div class="main-container">
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
        <div class="container mx-auto py-8 pt-20">
            <h2 class="text-3xl font-bold mb-6 text-center">Welcome to FireSafe</h2>
            <div class="button-grid">
                <?php foreach ($ordered_categories as $category): ?>
                    <?php if ($category['id'] == 0): ?>
                        <a href="<?php echo htmlspecialchars($category['url'] . '?t=' . $cache_buster); ?>" class="category-button"><?php echo htmlspecialchars($category['name']); ?></a>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars($category['url'] . '?id=' . $category['id'] . '&t=' . $cache_buster); ?>" class="category-button"><?php echo htmlspecialchars($category['name']); ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
<?php
error_log("home.php: Step 36 - Script completed at " . microtime(true));
?>
