<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_log("category.php: Step 1 - Script started at " . microtime(true));
$request_uri = $_SERVER['REQUEST_URI'];
$request_id = md5($request_uri . microtime(true));
$_SESSION['current_request_id'] = $request_id;
unset($_SESSION['fixed_bar_rendered_' . $request_id]);
error_log("category.php: Step 4 - Request ID set: " . $request_id);
if (isset($_SESSION['page_rendered_' . $request_id]) && $_SESSION['page_rendered_' . $request_id]) {
    error_log("category.php: Step 5 - Already rendered for request_id: " . $request_id . ", exiting");
    session_write_close();
    exit;
}

error_log("category.php: Step 6 - Proceeding with script");
require_once 'config.php';
error_log("category.php: Step 7 - Config included at " . microtime(true));

if (!isset($_SESSION['user_id'])) {
    error_log("category.php: Step 8 - No user logged in, redirecting to index.php");
    session_write_close();
    header('Location: index.php');
    exit;
}
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
error_log("category.php: Step 9 - User logged in, ID: $user_id, Role: $role");

// Handle AJAX request to update sort order
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_sort_order') {
    $headers = getallheaders();
    error_log("category.php: Received update_sort_order request with headers: " . json_encode($headers));
    $raw_input = file_get_contents('php://input');
    error_log("category.php: Received update_sort_order request with raw input: " . $raw_input);
    error_log("category.php: Received update_sort_order request with POST data: " . json_encode($_POST));
    
    $record_ids_input = isset($_POST['record_ids']) ? $_POST['record_ids'] : '';

$category_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$uri_params = [];
parse_str($_SERVER['QUERY_STRING'] ?? '', $uri_params);
$raw_id = isset($uri_params['id']) ? $uri_params['id'] : 'not set';
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    error_log("category.php: Debug - Session incomplete, user_id: " . ($_SESSION['user_id'] ?? 'not set') . ", role: " . ($_SESSION['role'] ?? 'not set'));
}
error_log("category.php: Debug - Retrieved category_id: $category_id, Raw ID: $raw_id, Query String: " . ($_SERVER['QUERY_STRING'] ?? 'none') . ", URI: " . $_SERVER['REQUEST_URI'] . ", Session: " . json_encode($_SESSION));
if ($category_id === 0 && strtolower($raw_id) === '14') {
    $category_id = 14;
    error_log("category.php: Forcing category_id to 14 based on parsed URI param");
}

    $record_ids = !empty($record_ids_input) ? array_filter(array_map('intval', explode(',', $record_ids_input))) : [];
    if (empty($record_ids)) {
        error_log("category.php: No record IDs provided for sort order update");
        echo json_encode(['status' => 'error', 'message' => 'No record IDs provided']);
        session_write_close();
        exit;
    }

    error_log("category.php: Updating sort order for table: $table, category_id: $category_id, user_id: $user_id, record_ids: " . implode(',', $record_ids));

    $user_id_column = ($table === 'roster') ? 'created_by_user_id' : 'user_id';

    $result = $mysqli->query("SELECT id, $user_id_column, sort_order FROM $table WHERE $user_id_column = $user_id ORDER BY sort_order");
    if ($result) {
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        error_log("category.php: Before update, $table contents: " . json_encode($rows));
        $result->free();
    } else {
        error_log("category.php: Failed to fetch $table contents before update: " . $mysqli->error);
    }

    $success = true;
    $total_updated = 0;
    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("UPDATE $table SET sort_order = ? WHERE id = ? AND $user_id_column = ?");
        if (!$stmt) {
            error_log("category.php: Failed to prepare sort order update statement for $table: " . $mysqli->error);
            echo json_encode(['status' => 'error', 'message' => 'Failed to prepare statement: ' . $mysqli->error]);
            $mysqli->rollback();
            session_write_close();
            exit;
        }

        foreach ($record_ids as $index => $record_id) {
            $check_stmt = $mysqli->prepare("SELECT id, $user_id_column FROM $table WHERE id = ? AND $user_id_column = ?");
            $check_stmt->bind_param('ii', $record_id, $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows === 0) {
                error_log("category.php: Record ID $record_id not found or $user_id_column mismatch in $table for user_id: $user_id");
                continue;
            }
            $record = $check_result->fetch_assoc();
            error_log("category.php: Found record ID $record_id with $user_id_column: " . $record[$user_id_column]);
            $check_stmt->close();

            $stmt->bind_param('iii', $index, $record_id, $user_id);
            if ($stmt->execute()) {
                $affected_rows = $stmt->affected_rows;
                if ($affected_rows > 0) {
                    $total_updated += $affected_rows;
                    error_log("category.php: Updated sort_order for record_id $record_id in $table to $index");
                } else {
                    error_log("category.php: No rows updated for record_id $record_id in $table (possible mismatch or no change)");
                }
            } else {
                error_log("category.php: Failed to update sort_order for record_id $record_id in $table: " . $stmt->error);
                $success = false;
            }
        }
        $stmt->close();

        if ($success && $total_updated > 0) {
            $mysqli->commit();
            error_log("category.php: Transaction committed successfully");
        } else {
            $mysqli->rollback();
            error_log("category.php: Transaction rolled back due to failure or no updates");
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log("category.php: Transaction rolled back due to exception: " . $e->getMessage());
        $success = false;
    }

    $result = $mysqli->query("SELECT id, $user_id_column, sort_order FROM $table WHERE $user_id_column = $user_id ORDER BY sort_order");
    if ($result) {
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        error_log("category.php: After update, $table contents: " . json_encode($rows));
        $result->free();
    } else {
        error_log("category.php: Failed to fetch $table contents after update: " . $mysqli->error);
    }

    if ($success && $total_updated > 0) {
        echo json_encode(['status' => 'success', 'updated' => $total_updated]);
    } else {
        $message = $success ? 'No records were updated (check if IDs exist or $user_id_column matches)' : 'Failed to update some records';
        echo json_encode(['status' => 'error', 'message' => $message, 'updated' => $total_updated]);
    }
    session_write_close();
    exit;
}

// Handle batch delete
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'batch_delete' && isset($_POST['category_id'])) {
    $category_id = (int)$_POST['category_id'];
    if (isset($_POST['record_ids']) && is_array($_POST['record_ids'])) {
        $record_ids = array_map('intval', $_POST['record_ids']);
        if (!empty($record_ids)) {
            $table = $category_id == 2 ? 'instrument_inventory' : ($category_id == 3 ? 'uniform_inventory' : ($category_id == 4 ? 'music_library' : ($category_id == 8 ? 'roster' : '')));
            if (!$table) {
                error_log("category.php: Invalid table for batch delete, category_id: $category_id");
                session_write_close();
                header("Location: category.php?id=$category_id");
                exit;
            }
            $user_id_column = ($table === 'roster') ? 'created_by_user_id' : 'user_id';
            $placeholders = implode(',', array_fill(0, count($record_ids), '?'));
            $stmt = $mysqli->prepare("DELETE FROM $table WHERE id IN ($placeholders) AND $user_id_column = ?");
            if ($stmt) {
                $types = str_repeat('i', count($record_ids)) . 'i';
                $params = array_merge($record_ids, [$user_id]);
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) {
                    error_log("category.php: Successfully deleted " . $stmt->affected_rows . " records from $table for $user_id_column: $user_id");
                } else {
                    error_log("category.php: Failed to delete records from $table: " . $stmt->error);
                }
                $stmt->close();
            } else {
                error_log("category.php: Failed to prepare batch delete statement: " . $mysqli->error);
            }
        }
    }
    session_write_close();
    header("Location: category.php?id=$category_id&t=" . time());
    exit;
}

// Handle single record delete
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_record'])) {
    $record_id = (int)$_POST['record_id'];
    $category_id = (int)$_POST['category_id'];
    $table = $category_id == 2 ? 'instrument_inventory' : ($category_id == 3 ? 'uniform_inventory' : ($category_id == 4 ? 'music_library' : ($category_id == 8 ? 'roster' : '')));
    if (!$table) {
        error_log("category.php: Invalid table for single delete, category_id: $category_id");
        session_write_close();
        header("Location: category.php?id=$category_id");
        exit;
    }
    $user_id_column = ($table === 'roster') ? 'created_by_user_id' : 'user_id';
    $stmt = $mysqli->prepare("DELETE FROM $table WHERE id = ? AND $user_id_column = ?");
    $stmt->bind_param('ii', $record_id, $user_id);
    $stmt->execute();
    $stmt->close();
    session_write_close();
    header("Location: category.php?id=$category_id&t=" . time());
    exit;
}

$category_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
error_log("category.php: Step 10 - Fetching category ID: $category_id");
$category_stmt = $mysqli->prepare('SELECT id, name FROM categories WHERE id = ?');
if (!$category_stmt) {
    error_log("category.php: Step 11 - Prepare failed: " . $mysqli->error);
    session_write_close();
    header('Location: home.php');
    exit;
}
$category_stmt->bind_param('i', $category_id);
$category_stmt->execute();
$result = $category_stmt->get_result();
$category = $result->fetch_assoc();
$category_stmt->close();
if (!$category) {
    error_log("category.php: Step 13 - No category found for ID: $category_id, redirecting to home.php");
    session_write_close();
    header('Location: home.php');
    exit;
}
$category_name = $category['name'];
error_log("category.php: Step 14 - Category fetched: " . json_encode($category));

// Fetch data based on category
$profile = null;
if ($category_id == 1) {
    $stmt = $mysqli->prepare('SELECT * FROM user_profiles WHERE user_id = ?');
    if (!$stmt) {
        error_log("category.php: Failed to prepare SELECT statement for user_profiles: " . $mysqli->error);
        die("Failed to prepare database query. Please check the server logs.");
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $profile = $result->fetch_assoc();
    $stmt->close();
}

$inventory_records = [];
if ($category_id == 2) {
    $sort = isset($_GET['sort']) && in_array($_GET['sort'], ['instrument_type', 'brand', 'model', 'serial_no', 'asset_no', 'description', 'condition_notes']) ? $_GET['sort'] : 'sort_order';
    $order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';
    $query = "SELECT * FROM instrument_inventory WHERE user_id = ?";
    $numerical_columns = ['serial_no', 'asset_no'];
    if (in_array($sort, $numerical_columns)) {
        $query .= " ORDER BY CASE WHEN $sort REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, CASE WHEN $sort REGEXP '^[0-9]+$' THEN CAST($sort AS UNSIGNED) ELSE $sort END $order";
    } else if ($sort === 'sort_order') {
        $query .= " ORDER BY COALESCE(sort_order, 999999) $order, created_at ASC";
    } else {
        $query .= " ORDER BY $sort $order";
    }
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        error_log("category.php: Failed to prepare SELECT statement for instrument_inventory: " . $mysqli->error);
        die("Failed to prepare database query for instrument inventory. Please check the server logs.");
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $inventory_records[] = $row;
    }
    $stmt->close();
}

$uniform_records = [];
if ($category_id == 3) {
    $sort = isset($_GET['sort']) && in_array($_GET['sort'], [
        'item_type', 'item_no', 'size', 'inseam', 'waist', 'hips', 'notes'
    ]) ? $_GET['sort'] : 'sort_order';
    $order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';
    $query = "SELECT * FROM uniform_inventory WHERE user_id = ?";
    $numerical_columns = ['item_no'];
    if (in_array($sort, $numerical_columns)) {
        $query .= " ORDER BY CASE WHEN $sort REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, CASE WHEN $sort REGEXP '^[0-9]+$' THEN CAST($sort AS UNSIGNED) ELSE $sort END $order";
    } else if ($sort === 'sort_order') {
        $query .= " ORDER BY COALESCE(sort_order, 999999) $order, created_at ASC";
    } else {
        $query .= " ORDER BY $sort $order";
    }
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        error_log("category.php: Failed to prepare SELECT statement for uniform_inventory: " . $mysqli->error);
        die("Failed to prepare database query for uniform inventory. Please check the server logs.");
    }
    $stmt->bind_param('i', $user_id);
    if (!$stmt->execute()) {
        error_log("category.php: Failed to execute SELECT statement for uniform_inventory: " . $stmt->error);
        die("Failed to execute database query for uniform inventory. Please check the server logs.");
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $uniform_records[] = $row;
    }
    $stmt->close();
}

$music_records = [];
if ($category_id == 4) {
    $sort = isset($_GET['sort']) && in_array($_GET['sort'], [
        'library_no', 'title', 'composer', 'arranger', 'publisher', 'year', 'genre', 'difficulty', 'ensemble_type', 'last_performed'
    ]) ? $_GET['sort'] : 'sort_order';
    $order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';
    $query = "SELECT * FROM music_library WHERE user_id = ?";
    if ($sort === 'library_no') {
        $query .= " ORDER BY CASE WHEN library_no REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, CASE WHEN library_no REGEXP '^[0-9]+$' THEN CAST(library_no AS UNSIGNED) ELSE library_no END $order";
    } else if ($sort === 'sort_order') {
        $query .= " ORDER BY COALESCE(sort_order, 999999) $order, created_at ASC";
    } else {
        $query .= " ORDER BY $sort $order";
    }
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        error_log("category.php: Failed to prepare SELECT statement for music_library: " . $mysqli->error);
        die("Failed to prepare database query for music library. Please check the server logs.");
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $music_records[] = $row;
    }
    error_log("category.php: Fetched music_records for user_id $user_id: " . json_encode($music_records));
    $stmt->close();
}

$roster_records = [];
if ($category_id == 8) {
    $sort = isset($_GET['sort']) && in_array($_GET['sort'], ['last_name', 'first_name']) ? $_GET['sort'] : 'last_name';
    $order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';
    $query = "SELECT id, last_name, first_name FROM roster WHERE created_by_user_id = ? ORDER BY $sort $order";
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        error_log("category.php: Failed to prepare SELECT statement for roster overview: " . $mysqli->error);
        die("Failed to prepare database query for roster overview. Please check the server logs.");
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $roster_records[] = $row;
    }
    $stmt->close();
}

if ($category_id == 14) {
    error_log("category.php: Debug - Fetching data for category_id: 14");
    $users_records = [];
    $users_stmt = $mysqli->prepare("SELECT id, email, role FROM users ORDER BY id");
    if ($users_stmt === false) {
        error_log("category.php: Failed to prepare users query for ID 14: " . $mysqli->error);
    } else {
        $users_stmt->execute();
        $users_result = $mysqli->get_result();
        if ($users_result && $users_result->num_rows > 0) {
            while ($row = $users_result->fetch_assoc()) {
                $users_records[] = $row;
            }
        }
        $users_stmt->close();
        error_log("category.php: Fetched users_records for ID 14, rows: " . count($users_records));
    }
}


// $cache_buster = time();
// $cache_buster = ''; // Temporarily disable to test
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    error_log("category.php: Access denied to category.php?id=$category_id for non-admin role: " . ($_SESSION['role'] ?? 'unknown'));
    header('Location: home.php');
    exit;
}
$cache_buster = ($category_id != 14) ? time() : '';
$_SESSION['page_rendered_' . $request_id] = true;
error_log("category.php: Step 35 - Page marked as rendered");

// Clean up old page_rendered and form_rendered entries, keeping only the current request
foreach ($_SESSION as $key => $value) {
    if ((strpos($key, 'page_rendered_') === 0 || strpos($key, 'form_rendered_') === 0) && $key !== 'page_rendered_' . $request_id) {
        unset($_SESSION[$key]);
    }
}
error_log("category.php: Cleaned up session, current size: " . strlen(serialize($_SESSION)));

session_write_close();
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FireSafe - <?php echo htmlspecialchars($category_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    console.log('DOMContentLoaded event fired at', new Date().toISOString());
    initializeEventListeners();
});

if (document.readyState === 'complete' || document.readyState === 'interactive') {
    console.log('Document already loaded, initializing immediately at', new Date().toISOString());
    initializeEventListeners();
}

if (!window.location.search.includes('t=') && window.location.search.indexOf('id=') === -1) {
    window.location.href = window.location.pathname + '?id=<?php echo $category_id; ?>&t=<?php echo $cache_buster; ?>';
} else if (window.location.search.includes('id=') && !window.location.search.includes('t=')) {
    window.location.href = window.location.pathname + window.location.search + '&t=<?php echo $cache_buster; ?>';
}
</script>

<style>
    .main-container { 
        display: flex; 
        flex-wrap: wrap;
        width: 100%; 
        padding-top: 36px; 
    }
    .drag-handle { cursor: move; font-size: 16px; color: #666; }
    .drag-over { border: 2px dashed #000; background-color: #f0f0f0; }
    .drag-over-highlight { background-color: #d1e7ff; }
    .drag-over-top { border-top: 2px dashed #000; }
    .drag-over-bottom { border-bottom: 2px dashed #000; }
    tr.dragging { opacity: 0.5; background-color: #e0e0e0; }
    tr.selected { background-color: #e6f3ff; }

#editDeleteButtons { 
    display: flex; 
    align-items: center; 
    gap: 8px; 
    line-height: normal;
    visibility: hidden; /* Default to hidden instead of display: none */
}

#editDeleteButtons.visible {
    visibility: visible;
}
.edit-button { 
    background-color: #4CAF50; 
    color: white; 
    border: none; 
    border-radius: 5px; 
    cursor: pointer; 
    line-height: normal;
    margin: 0;
}

.edit-button:hover { 
    background-color: #45a049; 
}

.delete-button { 
    background-color: #ff4444; 
    color: white; 
    border: none; 
    border-radius: 5px; 
    cursor: pointer; 
    line-height: normal;
    margin: 0;
}

.delete-button:hover { 
    background-color: #cc0000; 
}
.fixed-bar { 
    position: fixed; 
    top: 56px; 
    left: 0; 
    right: 0; 
    background-color: #ffffff; 
    z-index: 20; 
    padding: 0 1rem;
    display: flex; 
    align-items: center;
    justify-content: space-between;
    height: 64px;
}

}
    .fixed-bar::before { 
        content: ''; 
        position: absolute; 
        top: 0; 
        left: 0; 
        width: 256px; 
        height: 100%; 
        background-color: #2563eb; 
        z-index: -1; 
    }
    .fixed-bar h2 {
        white-space: nowrap;
        margin: 0;
    }
    .fixed-bar button {
        white-space: nowrap;
    }
    .fixed-bar .flex.items-center {
        display: flex;
        align-items: center;
        height: 100%;
        padding-top: 4px;
    }
    .fixed-bar .flex.items-center > * {
        margin-top: 0;
        margin-bottom: 0;
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
    .table-scroll-wrapper {
        overflow-x: auto;
        width: 100%;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: auto;
        scrollbar-color: #888 #e0e0e0;
    }
    .table-scroll-wrapper::-webkit-scrollbar {
        height: 12px;
    }
    .table-scroll-wrapper::-webkit-scrollbar-track {
        background: #e0e0e0;
    }
    .table-scroll-wrapper::-webkit-scrollbar-thumb {
        background-color: #888;
        border-radius: 6px;
        border: 2px solid #e0e0e0;
    }
    .table-scroll-wrapper::-webkit-scrollbar-thumb:hover {
        background-color: #555;
    }
    .overflow-x-auto { 
        overflow-y: auto;
        overflow-x: visible;
        width: 100%;
        max-height: calc(100vh - 200px);
        -webkit-overflow-scrolling: touch;
        scrollbar-width: auto;
        scrollbar-color: #888 #e0e0e0;
        position: relative;
    }
    .overflow-x-auto::-webkit-scrollbar {
        width: 12px;
    }
    .overflow-x-auto::-webkit-scrollbar-track {
        background: #e0e0e0;
    }
    .overflow-x-auto::-webkit-scrollbar-thumb {
        background-color: #888;
        border-radius: 6px;
        border: 2px solid #e0e0e0;
    }
    .overflow-x-auto::-webkit-scrollbar-thumb:hover {
        background-color: #555;
    }
    table.min-w-full { 
        width: auto;
        position: relative; 
    }
    #importModal, #formModal { 
        position: fixed; 
        inset: 0; 
        background-color: rgba(0, 0, 0, 0.5); 
        z-index: 50; 
        display: flex; 
        align-items: flex-start; 
        justify-content: center; 
        padding-top: 80px;
        overflow-y: auto; 
    }
    #importModal.hidden, #formModal.hidden {
        display: none !important;
    }
    #formModal .modal-content {
        width: 100%;
        max-width: 896px;
        background-color: #fff;
        border-radius: 0.5rem;
        padding: 1rem;
        max-height: 90vh;
        min-height: 275px;
        overflow-y: auto;
        transition: min-height 0.3s ease;
    }
    #formIframe {
        width: 100%;
        border: none;
    }
    .text-center {
        text-align: center;
    }
    .text-right {
        text-align: right;
    }
    td.text-center, th.text-center {
        text-align: center;
    }
    td.text-right, th.text-right {
        text-align: right;
    }
    th {
        vertical-align: bottom;
        white-space: nowrap;
    }
    th.checkbox-header {
        padding-bottom: 0.5rem;
    }
    #instrumentTable, #uniformTable, #musicTable, #rosterTable {
        table-layout: auto;
        border-collapse: collapse;
    }
    #instrumentTable thead, #uniformTable thead, #musicTable thead, #rosterTable thead {
        position: sticky;
        top: 0px;
        z-index: 10;
        background-color: #ffffff;
        box-shadow: 0 2px 0 0 #e5e7eb;
    }
    #instrumentTable th, #uniformTable th, #musicTable th, #rosterTable th {
        border: 2px solid #e5e7eb;
        background-color: #ffffff;
        box-shadow: none;
    }
    .instrument-col-reorder { min-width: 64px; width: auto; max-width: 159px; }
    .instrument-col-checkbox { min-width: 110px; width: auto; max-width: 159px; }
    .instrument-col-instrument-type { min-width: 88px; width: auto; max-width: 159px; }
    .instrument-col-brand { min-width: 48px; width: auto; max-width: 159px; }
    .instrument-col-model { min-width: 48px; width: auto; max-width: 159px; }
    .instrument-col-serial-no { min-width: 56px; width: auto; max-width: 159px; }
    .instrument-col-asset-no { min-width: 48px; width: auto; max-width: 159px; }
    .instrument-col-description { min-width: 96px; width: auto; max-width: 159px; }
    .instrument-col-condition-notes { min-width: 80px; width: auto; max-width: 159px; }
    .instrument-col-actions { min-width: 64px; width: auto; max-width: 159px; }
    .uniform-col-reorder { min-width: 64px; width: auto; max-width: 159px; }
    .uniform-col-checkbox { min-width: 110px; width: auto; max-width: 159px; }
    .uniform-col-item-type { min-width: 40px; width: auto; max-width: 159px; }
    .uniform-col-item-no { min-width: 40px; width: auto; max-width: 159px; }
    .uniform-col-size { min-width: 40px; width: auto; max-width: 159px; }
    .uniform-col-inseam { min-width: 56px; width: auto; max-width: 159px; }
    .uniform-col-waist { min-width: 48px; width: auto; max-width: 159px; }
    .uniform-col-hips { min-width: 40px; width: auto; max-width: 159px; }
    .uniform-col-notes { min-width: 48px; width: auto; max-width: 159px; }
    .uniform-col-actions { min-width: 64px; width: auto; max-width: 159px; }
    .music-col-reorder { min-width: 64px; width: auto; max-width: 159px; }
    .music-col-checkbox { min-width: 110px; width: auto; max-width: 159px; }
    .music-col-library-no { min-width: 64px; width: auto; max-width: 159px; }
    .music-col-title { min-width: 48px; width: auto; max-width: 159px; }
    .music-col-composer { min-width: 72px; width: auto; max-width: 159px; }
    .music-col-arranger { min-width: 72px; width: auto; max-width: 159px; }
    .music-col-publisher { min-width: 80px; width: auto; max-width: 159px; }
    .music-col-year { min-width: 40px; width: auto; max-width: 159px; }
    .music-col-genre { min-width: 48px; width: auto; max-width: 159px; }
    .music-col-difficulty { min-width: 88px; width: auto; max-width: 159px; }
    .music-col-ensemble-type { min-width: 72px; width: auto; max-width: 159px; }
    .music-col-last-performed { min-width: 80px; width: auto; max-width: 159px; }
    .music-col-actions { min-width: 64px; width: auto; max-width: 159px; }
    .roster-col-last-name { min-width: 72px; width: auto; max-width: 159px; }
    .roster-col-first-name { min-width: 72px; width: auto; max-width: 159px; }
    .roster-col-actions { min-width: 64px; width: auto; max-width: 159px; }
    .content-wrapper {
        flex: 1;
        margin-left: 266px;
        min-width: 0;
        overflow-x: visible;
    }
    .container {
        max-width: none;
        width: 100%;
    }
    #tableSearch {
        width: 300px;
        margin-left: 8px;
    }
</style>
</head>
<body class="bg-gray-100 min-h-screen">
<?php if (in_array($category_id, [2, 3, 4, 8])): ?>
    <?php 
    if (!isset($_SESSION['fixed_bar_rendered_' . $request_id])): 
        $_SESSION['fixed_bar_rendered_' . $request_id] = true;
        error_log("category.php: Rendering fixed-bar for category_id: $category_id at " . microtime(true));
    ?>
    <div class="fixed-bar">
        <div class="flex items-center">
            <div class="sidebar-offset flex items-center">
                <h2 class="text-xl font-bold text-gray-800 text-left"><?php echo htmlspecialchars($category_name); ?></h2>
                <div class="ml-4 flex items-center">
                    <?php if ($category_id == 2): ?>
                        <button onclick="openFormModal('instrument_inventory_form.php')" class="inline-block bg-blue-600 text-white p-2 rounded-md hover:bg-blue-700 mr-2">Add New Record</button>
                    <?php elseif ($category_id == 3): ?>
                        <button onclick="openFormModal('uniform_inventory_form.php')" class="inline-block bg-blue-600 text-white p-2 rounded-md hover:bg-blue-700 mr-2">Add New Record</button>
                    <?php elseif ($category_id == 4): ?>
                        <button onclick="openFormModal('music_library_form.php')" class="inline-block bg-blue-600 text-white p-2 rounded-md hover:bg-blue-700 mr-2">Add New Record</button>
                    <?php endif; ?>
                    <?php if ($category_id != 8): ?>
                        <button onclick="openImportModal(<?php echo $category_id; ?>)" class="inline-block bg-green-600 text-white p-2 rounded-md hover:bg-green-700 mr-2">Import</button>
                        <input type="text" id="tableSearch" placeholder="Search table..." class="p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($category_id != 8): ?>
            <div class="flex items-center">
                <div id="editDeleteButtons" class="flex items-center">
                    <button id="editButton" class="edit-button p-2 mr-2" onclick="editSelected()">Edit Selected</button>
                    <button id="deleteButton" class="delete-button p-2" onclick="deleteSelected()">Delete Selected</button>
                </div>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($category_id == 14): ?>
    <?php error_log("category.php: Debug - Entering where it says category_id == 14") ?>
    <div class="content-container">
        <h2 class="text-2xl font-bold mb-4">User Management</h2>
        <table class="min-w-full bg-white border">
            <thead>
                <tr>
                    <th class="py-2 px-4 border text-center">ID</th>
                    <th class="py-2 px-4 border text-center">Email</th>
                    <th class="py-2 px-4 border text-center">Role</th>
                </tr>
            </thead>
            <tbody>
                <?php
                error_log("category.php: Debug - Entering elseif block for category_id: $category_id");
                if (empty($users_records)) {
                    echo "<tr><td colspan='3' class='py-2 px-4 border text-center'>No users found</td></tr>";
                } else {
                    foreach ($users_records as $user) {
                        echo "<tr>";
                        echo "<td class='py-2 px-4 border text-center'>" . htmlspecialchars($user['id']) . "</td>";
                        echo "<td class='py-2 px-4 border text-center'>" . htmlspecialchars($user['email']) . "</td>";
                        echo "<td class='py-2 px-4 border text-center'>" . htmlspecialchars($user['role']) . "</td>";
                        echo "</tr>";
                    }
                }
                ?>
            </tbody>
        </table>
        <a href="home.php?t=<?php echo $cache_buster; ?>" class="mt-4 inline-block text-blue-600 hover:underline">Back to Home</a>
    </div>

    <?php else: ?>
        <?php 
        error_log("category.php: Skipped rendering fixed-bar for category_id: $category_id (already rendered) at " . microtime(true));
        ?>
    <?php endif; ?>
<?php endif; ?>

<div class="main-container">
    <?php 
    session_start();
    if (!isset($_SESSION['sidebar_rendered_' . $request_id])) {
        include 'sidebar.php';
        $_SESSION['sidebar_rendered_' . $request_id] = true;
        error_log("category.php: Sidebar rendered for request_id: " . $request_id);
    } else {
        error_log("category.php: Sidebar already rendered for request_id: " . $request_id . ", skipping");
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
            <?php if (!in_array($category_id, [2, 3, 4, 8])): ?>
                <h2 class="text-3xl font-bold mb-6"><?php echo htmlspecialchars($category_name); ?></h2>
            <?php endif; ?>
            <?php if ($category_id == 1): ?>
                <?php if ($profile): ?>
                    <div class="mb-8">
                        <h3 class="text-2xl font-semibold mb-4">Guardian 1 Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div><p class="font-medium">First Name:</p><p><?php echo htmlspecialchars($profile['guardian1_first_name'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Last Name:</p><p><?php echo htmlspecialchars($profile['guardian1_last_name'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Street Address 1:</p><p><?php echo htmlspecialchars($profile['guardian1_street_address1'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Street Address 2:</p><p><?php echo htmlspecialchars($profile['guardian1_street_address2'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">City:</p><p><?php echo htmlspecialchars($profile['guardian1_city'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">State:</p><p><?php echo htmlspecialchars($profile['guardian1_state'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Zip Code:</p><p><?php echo htmlspecialchars($profile['guardian1_zip_code'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Phone 1:</p><p><?php echo htmlspecialchars($profile['guardian1_phone1'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Phone 2:</p><p><?php echo htmlspecialchars($profile['guardian1_phone2'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Email:</p><p><?php echo htmlspecialchars($profile['guardian1_email'] ?: 'Not provided'); ?></p></div>
                        </div>
                    </div>
                    <div class="mb-8">
                        <h3 class="text-2xl font-semibold mb-4">Guardian 2 Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div><p class="font-medium">First Name:</p><p><?php echo htmlspecialchars($profile['guardian2_first_name'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Last Name:</p><p><?php echo htmlspecialchars($profile['guardian2_last_name'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Street Address 1:</p><p><?php echo htmlspecialchars($profile['guardian2_street_address1'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Street Address 2:</p><p><?php echo htmlspecialchars($profile['guardian2_street_address2'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">City:</p><p><?php echo htmlspecialchars($profile['guardian2_city'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">State:</p><p><?php echo htmlspecialchars($profile['guardian2_state'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Zip Code:</p><p><?php echo htmlspecialchars($profile['guardian2_zip_code'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Phone 1:</p><p><?php echo htmlspecialchars($profile['guardian2_phone1'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Phone 2:</p><p><?php echo htmlspecialchars($profile['guardian2_phone2'] ?: 'Not provided'); ?></p></div>
                            <div><p class="font-medium">Email:</p><p><?php echo htmlspecialchars($profile['guardian2_email'] ?: 'Not provided'); ?></p></div>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-gray-600">No profile data available. Please fill out your profile.</p>
                <?php endif; ?>
                <a href="user_profile.php" class="mt-4 inline-block bg-blue-600 text-white p-2 rounded-md hover:bg-blue-700">Edit Profile</a>
            <?php elseif ($category_id == 2): ?>
                <div class="content-container">
                    <div class="table-scroll-wrapper">
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-white border" id="instrumentTable">
                                <thead>
                                    <tr>
                                        <th class="py-2 px-4 border text-center instrument-col-reorder">Reorder</th>
                                        <th class="py-2 px-4 border text-center checkbox-header instrument-col-checkbox"><input type="checkbox" id="selectAll"><br><span id="selectAllHeader">Select All</span></th>
                                        <th class="py-2 px-4 border text-center instrument-col-instrument-type"><a href="category.php?id=2&sort=instrument_type&order=<?php echo $sort === 'instrument_type' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Instrument Type <?php if ($sort === 'instrument_type') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center instrument-col-brand"><a href="category.php?id=2&sort=brand&order=<?php echo $sort === 'brand' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Brand <?php if ($sort === 'brand') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center instrument-col-model"><a href="category.php?id=2&sort=model&order=<?php echo $sort === 'model' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Model <?php if ($sort === 'model') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center instrument-col-serial-no"><a href="category.php?id=2&sort=serial_no&order=<?php echo $sort === 'serial_no' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Serial No. <?php if ($sort === 'serial_no') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center instrument-col-asset-no"><a href="category.php?id=2&sort=asset_no&order=<?php echo $sort === 'asset_no' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Asset No. <?php if ($sort === 'asset_no') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center instrument-col-description"><a href="category.php?id=2&sort=description&order=<?php echo $sort === 'description' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Description <?php if ($sort === 'description') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center instrument-col-condition-notes"><a href="category.php?id=2&sort=condition_notes&order=<?php echo $sort === 'condition_notes' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Condition/Notes <?php if ($sort === 'condition_notes') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center instrument-col-actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventory_records as $record): ?>
                                        <tr data-id="<?php echo $record['id']; ?>">
                                            <td class="py-2 px-4 border text-center instrument-col-reorder"><span class="drag-handle" draggable="true">☰</span></td>
                                            <td class="py-2 px-4 border text-center instrument-col-checkbox"><input type="checkbox" class="rowCheckbox" value="<?php echo $record['id']; ?>"></td>
                                            <td class="py-2 px-4 border instrument-col-instrument-type"><?php echo htmlspecialchars($record['instrument_type']); ?></td>
                                            <td class="py-2 px-4 border instrument-col-brand"><?php echo htmlspecialchars($record['brand']); ?></td>
                                            <td class="py-2 px-4 border text-center instrument-col-model"><?php echo htmlspecialchars($record['model']); ?></td>
                                            <td class="py-2 px-4 border text-center instrument-col-serial-no"><?php echo htmlspecialchars($record['serial_no']); ?></td>
                                            <td class="py-2 px-4 border text-center instrument-col-asset-no"><?php echo htmlspecialchars($record['asset_no']); ?></td>
                                            <td class="py-2 px-4 border instrument-col-description"><?php echo htmlspecialchars($record['description']); ?></td>
                                            <td class="py-2 px-4 border instrument-col-condition-notes"><?php echo htmlspecialchars($record['condition_notes']); ?></td>
                                            <td class="py-2 px-4 border text-center instrument-col-actions">
                                                <a href="#" onclick="openFormModal('instrument_inventory_form.php?record_id=<?php echo $record['id']; ?>'); return false;" class="text-blue-600 hover:underline mr-2">Edit</a>
                                                <form method="POST" action="" class="inline">
                                                    <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                                    <input type="hidden" name="category_id" value="2">
                                                    <button type="submit" name="delete_record" class="text-red-600 hover:underline" onclick="return confirm('Are you sure you want to delete this record?')">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php if (empty($inventory_records)): ?>
                        <p class="text-gray-600 mt-4">No instrument inventory records found.</p>
                    <?php endif; ?>
                    <a href="home.php?t=<?php echo $cache_buster; ?>" class="mt-4 inline-block text-blue-600 hover:underline">Back to Home</a>
                </div>
            <?php elseif ($category_id == 3): ?>
                <div class="content-container">
                    <div class="table-scroll-wrapper">
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-white border" id="uniformTable">
                                <thead>
                                    <tr>
                                        <th class="py-2 px-4 border text-center uniform-col-reorder">Reorder</th>
                                        <th class="py-2 px-4 border text-center checkbox-header uniform-col-checkbox"><input type="checkbox" id="selectAll"><br><span id="selectAllHeader">Select All</span></th>
                                        <th class="py-2 px-4 border text-center uniform-col-item-type"><a href="category.php?id=3&sort=item_type&order=<?php echo $sort === 'item_type' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Item Type <?php if ($sort === 'item_type') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center uniform-col-item-no"><a href="category.php?id=3&sort=item_no&order=<?php echo $sort === 'item_no' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Item No. <?php if ($sort === 'item_no') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center uniform-col-size"><a href="category.php?id=3&sort=size&order=<?php echo $sort === 'size' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Size <?php if ($sort === 'size') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center uniform-col-inseam"><a href="category.php?id=3&sort=inseam&order=<?php echo $sort === 'inseam' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Inseam <?php if ($sort === 'inseam') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center uniform-col-waist"><a href="category.php?id=3&sort=waist&order=<?php echo $sort === 'waist' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Waist <?php if ($sort === 'waist') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center uniform-col-hips"><a href="category.php?id=3&sort=hips&order=<?php echo $sort === 'hips' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Hips <?php if ($sort === 'hips') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center uniform-col-notes"><a href="category.php?id=3&sort=notes&order=<?php echo $sort === 'notes' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Notes <?php if ($sort === 'notes') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center uniform-col-actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($uniform_records as $record): ?>
                                        <tr data-id="<?php echo $record['id']; ?>">
                                            <td class="py-2 px-4 border text-center uniform-col-reorder"><span class="drag-handle" draggable="true">☰</span></td>
                                            <td class="py-2 px-4 border text-center uniform-col-checkbox"><input type="checkbox" class="rowCheckbox" value="<?php echo $record['id']; ?>"></td>
                                            <td class="py-2 px-4 border uniform-col-item-type"><?php echo htmlspecialchars($record['item_type']); ?></td>
                                            <td class="py-2 px-4 border text-center uniform-col-item-no"><?php echo htmlspecialchars($record['item_no']); ?></td>
                                            <td class="py-2 px-4 border text-center uniform-col-size"><?php echo htmlspecialchars($record['size']); ?></td>
                                            <td class="py-2 px-4 border text-center uniform-col-inseam"><?php echo htmlspecialchars($record['inseam']); ?></td>
                                            <td class="py-2 px-4 border text-center uniform-col-waist"><?php echo htmlspecialchars($record['waist']); ?></td>
                                            <td class="py-2 px-4 border text-center uniform-col-hips"><?php echo htmlspecialchars($record['hips']); ?></td>
                                            <td class="py-2 px-4 border uniform-col-notes"><?php echo htmlspecialchars($record['notes']); ?></td>
                                            <td class="py-2 px-4 border text-center uniform-col-actions">
                                                <a href="#" onclick="openFormModal('uniform_inventory_form.php?record_id=<?php echo $record['id']; ?>'); return false;" class="text-blue-600 hover:underline mr-2">Edit</a>
                                                <form method="POST" action="" class="inline">
                                                    <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                                    <input type="hidden" name="category_id" value="3">
                                                    <button type="submit" name="delete_record" class="text-red-600 hover:underline" onclick="return confirm('Are you sure you want to delete this record?')">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php if (empty($uniform_records)): ?>
                        <p class="text-gray-600 mt-4">No uniform inventory records found.</p>
                    <?php endif; ?>
                    <a href="home.php?t=<?php echo $cache_buster; ?>" class="mt-4 inline-block text-blue-600 hover:underline">Back to Home</a>
            </div>
            <?php elseif ($category_id == 4): ?>
                <div class="content-container">
                    <div class="table-scroll-wrapper">
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-white border" id="musicTable">
                                <thead>
                                    <tr>
                                        <th class="py-2 px-4 border text-center music-col-reorder">Reorder</th>
                                        <th class="py-2 px-4 border text-center checkbox-header music-col-checkbox"><input type="checkbox" id="selectAll"><br><span id="selectAllHeader">Select All</span></th>
                                        <th class="py-2 px-4 border text-center music-col-library-no"><a href="category.php?id=4&sort=library_no&order=<?php echo $sort === 'library_no' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="For better sorting, use leading zeroes (i.e. '001' instead of '1').">Library No. <?php if ($sort === 'library_no') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center music-col-title"><a href="category.php?id=4&sort=title&order=<?php echo $sort === 'title' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Title <?php if ($sort === 'title') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center music-col-composer"><a href="category.php?id=4&sort=composer&order=<?php echo $sort === 'composer' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Composer <?php if ($sort === 'composer') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center music-col-arranger"><a href="category.php?id=4&sort=arranger&order=<?php echo $sort === 'arranger' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Arranger <?php if ($sort === 'arranger') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center music-col-publisher"><a href="category.php?id=4&sort=publisher&order=<?php echo $sort === 'publisher' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Publisher <?php if ($sort === 'publisher') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center music-col-year"><a href="category.php?id=4&sort=year&order=<?php echo $sort === 'year' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Year <?php if ($sort === 'year') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center music-col-genre"><a href="category.php?id=4&sort=genre&order=<?php echo $sort === 'genre' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Genre <?php if ($sort === 'genre') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center music-col-difficulty"><a href="category.php?id=4&sort=difficulty&order=<?php echo $sort === 'difficulty' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Difficulty <?php if ($sort === 'difficulty') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center music-col-ensemble-type"><a href="category.php?id=4&sort=ensemble_type&order=<?php echo $sort === 'ensemble_type' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Ensemble Type <?php if ($sort === 'ensemble_type') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center music-col-last-performed"><a href="category.php?id=4&sort=last_performed&order=<?php echo $sort === 'last_performed' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Last Performed <?php if ($sort === 'last_performed') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center music-col-actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($music_records as $record): ?>
                                        <tr data-id="<?php echo $record['id']; ?>">
                                            <td class="py-2 px-4 border text-center music-col-reorder"><span class="drag-handle" draggable="true">☰</span></td>
                                            <td class="py-2 px-4 border text-center music-col-checkbox"><input type="checkbox" class="rowCheckbox" value="<?php echo $record['id']; ?>"></td>
                                            <td class="py-2 px-4 border text-center music-col-library-no"><?php echo htmlspecialchars($record['library_no']); ?></td>
                                            <td class="py-2 px-4 border music-col-title"><?php echo htmlspecialchars($record['title']); ?></td>
                                            <td class="py-2 px-4 border music-col-composer"><?php echo htmlspecialchars($record['composer']); ?></td>
                                            <td class="py-2 px-4 border music-col-arranger"><?php echo htmlspecialchars($record['arranger']); ?></td>
                                            <td class="py-2 px-4 border music-col-publisher"><?php echo htmlspecialchars($record['publisher']); ?></td>
                                            <td class="py-2 px-4 border text-center music-col-year"><?php echo htmlspecialchars($record['year']); ?></td>
                                            <td class="py-2 px-4 border text-center music-col-genre"><?php echo htmlspecialchars($record['genre']); ?></td>
                                            <td class="py-2 px-4 border text-center music-col-difficulty"><?php echo htmlspecialchars($record['difficulty']); ?></td>
                                            <td class="py-2 px-4 border text-center music-col-ensemble-type"><?php echo htmlspecialchars($record['ensemble_type']); ?></td>
                                            <td class="py-2 px-4 border text-center music-col-last-performed"><?php echo htmlspecialchars($record['last_performed']); ?></td>
                                            <td class="py-2 px-4 border text-center music-col-actions">
                                                <a href="#" onclick="openFormModal('music_library_form.php?record_id=<?php echo $record['id']; ?>'); return false;" class="text-blue-600 hover:underline mr-2">Edit</a>
                                                <form method="POST" action="" class="inline">
                                                    <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                                    <input type="hidden" name="category_id" value="4">
                                                    <button type="submit" name="delete_record" class="text-red-600 hover:underline" onclick="return confirm('Are you sure you want to delete this record?')">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php if (empty($music_records)): ?>
                        <p class="text-gray-600 mt-4">No music library records found.</p>
                    <?php endif; ?>
                    <a href="home.php?t=<?php echo $cache_buster; ?>" class="mt-4 inline-block text-blue-600 hover:underline">Back to Home</a>
            </div>
            <?php elseif ($category_id == 5): ?>
                <p class="text-gray-600">This section is under development. Check back soon for updates!</p>
                <a href="home.php?t=<?php echo $cache_buster; ?>" class="mt-4 inline-block text-blue-600 hover:underline">Back to Home</a>
            <?php elseif ($category_id == 8): ?>
                <div class="content-container">
                    <div class="table-scroll-wrapper">
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-white border" id="rosterTable">
                                <thead>
                                    <tr>
                                        <th class="py-2 px-4 border text-center roster-col-last-name"><a href="category.php?id=8&sort=last_name&order=<?php echo $sort === 'last_name' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Last Name <?php if ($sort === 'last_name') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center roster-col-first-name"><a href="category.php?id=8&sort=first_name&order=<?php echo $sort === 'first_name' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">First Name <?php if ($sort === 'first_name') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th class="py-2 px-4 border text-center roster-col-actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($roster_records as $record): ?>
                                        <tr data-id="<?php echo $record['id']; ?>">
                                            <td class="py-2 px-4 border roster-col-last-name"><?php echo htmlspecialchars($record['last_name']); ?></td>
                                            <td class="py-2 px-4 border roster-col-first-name"><?php echo htmlspecialchars($record['first_name']); ?></td>
                                            <td class="py-2 px-4 border text-center roster-col-actions">
                                                <a href="#" onclick="openFormModal('roster_form.php?record_id=<?php echo $record['id']; ?>'); return false;" class="text-blue-600 hover:underline mr-2">Edit</a>
                                                <form method="POST" action="" class="inline">
                                                    <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                                    <input type="hidden" name="category_id" value="8">
                                                    <button type="submit" name="delete_record" class="text-red-600 hover:underline" onclick="return confirm('Are you sure you want to delete this record?')">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php if (empty($roster_records)): ?>
                        <p class="text-gray-600 mt-4">No roster records found.</p>
                    <?php endif; ?>
                    <a href="home.php?t=<?php echo $cache_buster; ?>" class="mt-4 inline-block text-blue-600 hover:underline">Back to Home</a>
                </div>
            <?php else: ?>
                <?php error_log("category.php: Debug - Falling back to else clause for category_id: $category_id"); ?></p>
                <p class="text-gray-600">This section is under development. Check back soon for updates!</p>
                <a href="home.php?t=<?php echo $cache_buster; ?>" class="mt-4 inline-block text-blue-600 hover:underline">Back to Home</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<div id="importModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-start justify-center pt-10 overflow-y-auto">
<div class="bg-white rounded-lg p-6 w-full max-w-2xl max-h-[80vh] overflow-y-auto">
    <h3 class="text-xl font-bold mb-4">Import Data</h3>
        <div id="importStep1">
            <p class="mb-4">Upload a .csv or .tsv file to import data. Ensure there are no empty column names in the header row.</p>
            <input type="file" id="importFile" accept=".csv,.tsv" class="mb-4">
            <div class="flex justify-end">
                <button id="cancelImportButton" onclick="closeImportModal()" class="text-gray-600 hover:underline mr-4">Cancel</button>
                <button id="nextImportButton" onclick="parseFile()" class="bg-blue-600 text-white p-2 rounded-md hover:bg-blue-700">Next</button>
            </div>
        </div>
        <div id="importStep2" class="hidden">
            <p class="mb-4">Match the columns in your file to the fields in this category.</p>
            <div id="columnMappings" class="mb-4"></div>
            <div id="sampleData" class="mb-4 overflow-x-auto"></div>
            <div class="flex justify-end">
                <button id="backImportButton" onclick="backToStep1()" class="text-gray-600 hover:underline mr-4">Back</button>
                <button id="submitImportButton" onclick="submitImport()" class="bg-blue-600 text-white p-2 rounded-md hover:bg-blue-700">Import</button>
            </div>
        </div>
        <div id="importResult" class="hidden">
            <div id="importResultContent" class="mb-4"></div>
            <div class="flex justify-end">
                <button id="closeImportResultButton" onclick="closeImportModal()" class="bg-blue-600 text-white p-2 rounded-md hover:bg-blue-700">Close</button>
            </div>
        </div>
    </div>
</div>
<div id="formModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-start justify-center pt-10 overflow-y-auto">
<div class="modal-content">
    <iframe id="formIframe" class="w-full" frameborder="0"></iframe>
</div>
</div>
<script>
const pageCategoryId = <?php echo $category_id; ?>;
let lastChecked = null;
let fileData = null;
let currentCategoryId = null;

// Define closeFormModal early to ensure it's available
function closeFormModal() {
    console.log('closeFormModal called at', new Date().toISOString());
    try {
        const formModal = document.getElementById('formModal');
        const formIframe = document.getElementById('formIframe');
        if (formModal && formIframe) {
            formIframe.src = '';
            formIframe.style.height = '';
            const modalContent = document.querySelector('#formModal .modal-content');
            if (modalContent) {
                modalContent.style.minHeight = '275px';
            } else {
                console.warn('Modal content not found in #formModal');
            }
            formModal.classList.add('hidden');
            console.log('Form modal closed successfully');
        } else {
            console.error('Form modal or iframe not found in DOM', {
                formModal: !!formModal,
                formIframe: !!formIframe
            });
            throw new Error('Required DOM elements missing for closing modal');
        }
    } catch (error) {
        console.error('Error in closeFormModal:', error.message, error.stack);
    
}

function toggleEditDeleteButtons() {
    console.log('toggleEditDeleteButtons called');
    const rowCheckboxes = document.querySelectorAll('.rowCheckbox');
    const checkedCount = Array.from(rowCheckboxes).filter(cb => cb.checked).length;
    const totalCount = rowCheckboxes.length;
    console.log(`Checkbox counts - Total: ${totalCount}, Checked: ${checkedCount}`);

    const editDeleteButtons = document.getElementById('editDeleteButtons');
    const selectAllHeader = document.getElementById('selectAllHeader');
    
    if (editDeleteButtons) {
        console.log(`Checked count for edit/delete buttons: ${checkedCount}`);
        editDeleteButtons.classList.toggle('visible', checkedCount > 0);
    } else {
        console.error('editDeleteButtons element not found');
    }
    
    if (selectAllHeader) {
        if (totalCount > 0 && checkedCount === totalCount) {
            selectAllHeader.textContent = 'Deselect All';
            console.log('All checkboxes selected, header set to "Deselect All"');
        } else {
            selectAllHeader.textContent = 'Select All';
            console.log('Not all checkboxes selected, header set to "Select All"');
        }
    } else {
        console.error('selectAllHeader element not found');
    }
}

function toggleSelectAll() {
    console.log('toggleSelectAll called');
    const selectAllCheckbox = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.rowCheckbox');
    if (!selectAllCheckbox) {
        console.error('Select All checkbox not found in toggleSelectAll');
        return;
    }
    console.log(`Found ${checkboxes.length} row checkboxes`);
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
        const row = checkbox.closest('tr');
        if (row) {
            row.classList.toggle('selected', checkbox.checked);
        }
    });
    toggleEditDeleteButtons();
}

function handleCheckboxClick(event) {
    console.log('handleCheckboxClick called');
    const checkbox = event.target;
    if (!checkbox.classList.contains('rowCheckbox')) {
        console.log('Clicked element is not a rowCheckbox');
        return;
    }

    const row = checkbox.closest('tr');
    if (row) {
        row.classList.toggle('selected', checkbox.checked);
    }

    if (event.shiftKey && lastChecked && lastChecked !== checkbox) {
        const checkboxes = Array.from(document.querySelectorAll('.rowCheckbox'));
        const startIndex = checkboxes.indexOf(lastChecked);
        const endIndex = checkboxes.indexOf(checkbox);
        const start = Math.min(startIndex, endIndex);
        const end = Math.max(startIndex, endIndex);
        const shouldCheck = checkbox.checked;

        console.log(`Selecting range from index ${start} to ${end}, shouldCheck: ${shouldCheck}`);
        for (let i = start; i <= end; i++) {
            checkboxes[i].checked = shouldCheck;
            const row = checkboxes[i].closest('tr');
            if (row) {
                row.classList.toggle('selected', shouldCheck);
            }
        }
    }

    lastChecked = checkbox;
    toggleEditDeleteButtons();
}

function editSelected() {
    console.log('editSelected called');
    const checkboxes = document.querySelectorAll('.rowCheckbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one record to edit.');
        return;
    }
    const recordIds = Array.from(checkboxes).map(checkbox => checkbox.value);
    let formUrl;
    if (pageCategoryId === 2) {
        formUrl = 'instrument_inventory_form.php?record_ids=' + recordIds.join(',');
    } else if (pageCategoryId === 3) {
        formUrl = 'uniform_inventory_form.php?record_ids=' + recordIds.join(',');
    } else if (pageCategoryId === 4) {
        formUrl = 'music_library_form.php?record_ids=' + recordIds.join(',');
    } else if (pageCategoryId === 8) {
        formUrl = 'roster_form.php?record_ids=' + recordIds.join(',');
    }
    if (formUrl) {
        openFormModal(formUrl);
        console.log('Opened edit modal with URL:', formUrl);
    } else {
        console.error('No form URL defined for category ID:', pageCategoryId);
    }
}

function deleteSelected() {
    console.log('deleteSelected called');
    const checkboxes = document.querySelectorAll('.rowCheckbox:checked');
    if (checkboxes.length === 0) return;
    if (!confirm('Are you sure you want to delete the selected records?')) return;
    const recordIds = Array.from(checkboxes).map(checkbox => checkbox.value);
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'category.php?id=' + pageCategoryId;
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'batch_delete';
    form.appendChild(actionInput);
    const categoryInput = document.createElement('input');
    categoryInput.type = 'hidden';
    categoryInput.name = 'category_id';
    categoryInput.value = pageCategoryId;
    form.appendChild(categoryInput);
    recordIds.forEach(id => {
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'record_ids[]';
        idInput.value = id;
        form.appendChild(idInput);
    });
    document.body.appendChild(form);
    form.submit();
}

function setIframeHeight(url) {
    const formIframe = document.getElementById('formIframe');
    const modalContent = document.querySelector('#formModal .modal-content');
    if (formIframe && modalContent) {
        let height = '275px';
        if (url.includes('instrument_inventory_form.php')) {
            height = '330px';
        } else if (url.includes('music_library_form.php')) {
            height = '420px';
        } else if (url.includes('uniform_inventory_form.php')) {
            height = '375px';
        } else if (url.includes('roster_form.php')) {
            height = '480px';
        } else if (url.includes('manage_instrument_types.php') || url.includes('manage_uniform_types.php')) {
            height = '540px';
        }
        formIframe.style.height = height;
        modalContent.style.minHeight = height;
        console.log('Iframe height set to:', height, 'for URL:', url);
    } else {
        console.error('Form iframe or modal content not found in DOM');
    }
}

function openFormModal(url) {
    console.log('openFormModal called with url:', url);
    try {
        const formModal = document.getElementById('formModal');
        const formIframe = document.getElementById('formIframe');
        if (formModal && formIframe) {
            formIframe.src = url;
            setIframeHeight(url);
            formModal.classList.remove('hidden');
            formIframe.focus();
            console.log('Form modal opened with url:', url);
        } else {
            console.error('Form modal or iframe not found in DOM', {
                formModal: !!formModal,
                formIframe: !!formIframe
            });
            throw new Error('Required DOM elements missing for opening modal');
        }
    } catch (error) {
        console.error('Error in openFormModal:', error.message, error.stack);
    }
}

function parseFile() {
    console.log('parseFile called');
    const fileInput = document.getElementById('importFile');
    if (!fileInput.files.length) {
        alert('Please select a file to upload.');
        return;
    }
    const file = fileInput.files[0];
    console.log('File details:', {
        name: file.name,
        size: file.size,
        type: file.type
    });

    const serverMaxFileSize = 10 * 1024 * 1024;
    if (file.size > serverMaxFileSize) {
        alert('File size exceeds server limit of 10MB. Please upload a smaller file.');
        console.error('File size exceeds server limit:', file.size, 'bytes');
        return;
    }

    if (file.size === 0) {
        alert('The selected file is empty. Please upload a valid file.');
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        alert('File size exceeds 5MB. Please upload a smaller file.');
        return;
    }

    const formData = new FormData();
    formData.append('file', file);
    formData.append('action', 'parse');
    formData.append('category_id', currentCategoryId);
    fetch('import.php', {
        method: 'POST',
        body: formData
    })
        .then(response => {
            console.log('Fetch response status:', response.status);
            console.log('Fetch response headers:', response.headers.get('Content-Type'));
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const contentType = response.headers.get('Content-Type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    throw new Error(`Expected JSON, but received: ${text}`);
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Fetch response data:', data);
            if (data.error) {
                console.error('Parse error:', data.error);
                alert('Error: ' + data.error);
                return;
            }
            fileData = data;
            displayColumnMappings(data.headers, data.fields);
            displaySampleData(data.headers, data.sample_data);
            document.getElementById('importStep1').classList.add('hidden');
            document.getElementById('importStep2').classList.remove('hidden');
        })
        .catch(error => {
            console.error('Fetch error:', error);
            alert('An error occurred while parsing the file: ' + error.message);
        });
}

function displayColumnMappings(headers, fields) {
    console.log('displayColumnMappings called with headers:', headers, 'fields:', fields);
    const mappingsDiv = document.getElementById('columnMappings');
    mappingsDiv.innerHTML = '<h4 class="text-lg font-medium mb-2">Column Mappings</h4>';
    headers.forEach(header => {
        if (header.trim() === '') return;
        const div = document.createElement('div');
        div.className = 'mb-2';
        div.innerHTML = `
            <label class="block text-sm font-medium text-gray-700">${header}:</label>
            <select name="mapping_${header}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                <option value="">-- Ignore this column --</option>
                ${fields.map(field => `<option value="${field}">${field}</option>`).join('')}
            </select>
        `;
        mappingsDiv.appendChild(div);
    });
}

function displaySampleData(headers, sampleData) {
    console.log('displaySampleData called with headers:', headers, 'sampleData:', sampleData);
    const sampleDiv = document.getElementById('sampleData');
    sampleDiv.innerHTML = '<h4 class="text-lg font-medium mb-2">Sample Data (First 3 Rows)</h4>';
    if (sampleData.length === 0) {
        sampleDiv.innerHTML += '<p>No data to display.</p>';
        return;
    }
    const table = document.createElement('table');
    table.className = 'min-w-full bg-white border';
    const thead = document.createElement('thead');
    const headerRow = document.createElement('tr');
    headers.forEach(header => {
        const th = document.createElement('th');
        th.className = 'py-2 px-4 border';
        th.textContent = header || '(Empty)';
        headerRow.appendChild(th);
    });
    thead.appendChild(headerRow);
    table.appendChild(thead);
    const tbody = document.createElement('tbody');
    sampleData.forEach(row => {
        const tr = document.createElement('tr');
        headers.forEach((_, index) => {
            const td = document.createElement('td');
            td.className = 'py-2 px-4 border';
            td.textContent = row[index] || '';
            tr.appendChild(td);
        });
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    sampleDiv.appendChild(table);
}

function backToStep1() {
    console.log('backToStep1 called');
    document.getElementById('importStep1').classList.remove('hidden');
    document.getElementById('importStep2').classList.add('hidden');
}

function submitImport() {
    console.log('submitImport called');
    const mappings = {};
    const columnIndices = {};
    const headers = fileData.headers;
    headers.forEach((header, index) => {
        columnIndices[header] = index;
    });

    document.querySelectorAll('#columnMappings select').forEach(select => {
        const column = select.name.replace('mapping_', '');
        const field = select.value;
        if (field) {
            mappings[column] = {
                field: field,
                index: columnIndices[column]
            };
        }
    });

    const importData = fileData.data.map(row => {
        const mappedRow = {};
        Object.keys(mappings).forEach(column => {
            const { index } = mappings[column];
            mappedRow[column] = row[index];
        });
        return Object.values(mappedRow);
    });

    const formData = new FormData();
    formData.append('action', 'import');
    formData.append('category_id', currentCategoryId);
    formData.append('mappings', JSON.stringify(mappings));
    formData.append('data', JSON.stringify(importData));
    fetch('import.php', {
        method: 'POST',
        body: formData
    })
        .then(response => {
            console.log('Submit fetch response status:', response.status);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const contentType = response.headers.get('Content-Type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    throw new Error(`Expected JSON, but received: ${text}`);
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Submit fetch response data:', data);
            if (data.error) {
                console.error('Import error:', data.error);
                alert('Error: ' + data.error);
                return;
            }
            document.getElementById('importStep2').classList.add('hidden');
            const resultDiv = document.getElementById('importResult');
            const resultContent = document.getElementById('importResultContent');
            const totalRows = fileData.total_rows || fileData.data.length;
            resultContent.innerHTML = `
                <p class="text-green-600">Successfully imported ${data.imported} out of ${totalRows} data rows.</p>
                ${data.skipped > 0 ? `<p class="text-yellow-600">${data.skipped} rows were skipped due to errors.</p>` : ''}
            `;
            resultDiv.classList.remove('hidden');
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        })
        .catch(error => {
            console.error('Submit fetch error:', error);
            alert('An error occurred while importing the data: ' + error.message);
        });
}

function openImportModal(categoryId) {
    console.log('openImportModal called with categoryId:', categoryId);
    currentCategoryId = categoryId;
    const importModal = document.getElementById('importModal');
    if (importModal) {
        importModal.classList.remove('hidden');
        document.getElementById('importStep1').classList.remove('hidden');
        document.getElementById('importStep2').classList.add('hidden');
        document.getElementById('importResult').classList.add('hidden');
        document.getElementById('importFile').value = '';
        console.log('Import modal opened');
    } else {
        console.error('Import modal not found in DOM');
    }
}

function closeImportModal() {
    console.log('closeImportModal called');
    const importModal = document.getElementById('importModal');
    if (importModal) {
        importModal.classList.add('hidden');
        document.getElementById('importFile').value = '';
        fileData = null;
        console.log('Import modal closed');
    } else {
        console.error('Import modal not found in DOM');
    }
}

// Function to retry a fetch request with exponential backoff
async function fetchWithRetry(url, options, retries = 3, delay = 1000) {
    for (let attempt = 1; attempt <= retries; attempt++) {
        try {
            console.log(`Fetch attempt ${attempt} for URL: ${url}`);
            const response = await fetch(url, options);
            console.log(`Fetch attempt ${attempt} succeeded with status: ${response.status}`);
            return response;
        } catch (error) {
            if (attempt === retries) {
                console.error(`All ${retries} fetch attempts failed for URL: ${url}`, error);
                throw error;
            }
            console.warn(`Fetch attempt ${attempt} failed for URL: ${url}, retrying in ${delay}ms...`, error);
            await new Promise(resolve => setTimeout(resolve, delay));
            delay *= 2; // Exponential backoff
        }
    }
}

function initializeEventListeners() {
    console.log('initializeEventListeners called at', new Date().toISOString());

    const importModal = document.getElementById('importModal');
    const formModal = document.getElementById('formModal');
    const formIframe = document.getElementById('formIframe');
    if (importModal) {
        importModal.classList.add('hidden');
        console.log('Import modal ensured hidden on page load');
    } else {
        console.error('Import modal not found');
    }
    if (formModal) {
        formModal.classList.add('hidden');
        console.log('Form modal ensured hidden on page load');
    } else {
        console.error('Form modal not found');
    }

    const fixedBars = document.querySelectorAll('.fixed-bar');
    if (fixedBars.length > 1) {
        console.log(`Found ${fixedBars.length} fixed-bar elements, removing duplicates`);
        for (let i = 1; i < fixedBars.length; i++) {
            fixedBars[i].remove();
        }
    }

    if (formIframe) {
        formIframe.addEventListener('load', () => {
            const currentSrc = formIframe.src;
            if (currentSrc && !currentSrc.endsWith('about:blank')) {
                setIframeHeight(currentSrc);
            }
        });
        console.log('Iframe load listener attached');
    } else {
        console.error('Form iframe not found');
    }

    document.addEventListener('click', handleCheckboxClick);
    console.log('Document click listener attached for handleCheckboxClick');

    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', toggleSelectAll);
        console.log('Select All listener attached');
    } else {
        console.error('Select All checkbox not found');
    }

    let previousCheckboxCount = document.querySelectorAll('.rowCheckbox').length;
    console.log(`Initial rowCheckbox count: ${previousCheckboxCount}`);
    const observer = new MutationObserver((mutations) => {
        mutations.forEach(mutation => {
            if (mutation.addedNodes.length) {
                const currentCheckboxCount = document.querySelectorAll('.rowCheckbox').length;
                if (currentCheckboxCount !== previousCheckboxCount) {
                    console.log('Checkbox count changed from', previousCheckboxCount, 'to', currentCheckboxCount);
                    previousCheckboxCount = currentCheckboxCount;
                    const selectAllCheckbox = document.getElementById('selectAll');
                    if (selectAllCheckbox && !selectAllCheckbox.onchange) {
                        selectAllCheckbox.addEventListener('change', toggleSelectAll);
                        console.log('Reattached Select All listener');
                    }
                }
            }
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });

    const tableMap = {
        'instrumentTable': 'instrument_inventory',
        'uniformTable': 'uniform_inventory',
        'musicTable': 'music_library'
    };

    const tables = ['instrumentTable', 'uniformTable', 'musicTable'];
    tables.forEach(tableId => {
        const table = document.getElementById(tableId);
        if (!table) {
            console.error(`Table with ID ${tableId} not found`);
            return;
        }
        const tbody = table.querySelector('tbody');
        if (!tbody) {
            console.error(`Tbody not found in table ${tableId}`);
            return;
        }

        let draggedRows = [];
        let targetRow = null;

        const dragHandles = tbody.querySelectorAll('.drag-handle');
        console.log(`Found ${dragHandles.length} drag handles in table ${tableId}`);
        dragHandles.forEach(handle => {
            handle.setAttribute('draggable', 'true');
            handle.style.cursor = 'move';
            console.log(`Set draggable=true and cursor=move for drag handle in table ${tableId}`);
        });

        tbody.addEventListener('dragstart', (e) => {
            const target = e.target;
            if (!target.classList.contains('drag-handle')) {
                console.log('Dragstart event fired but target is not a drag-handle:', target);
                return;
            }
            console.log('Dragstart event fired for table:', tableId);
            const row = target.closest('tr');
            if (!row) {
                console.error('Could not find closest tr for drag handle');
                return;
            }
            const selectedRows = Array.from(tbody.querySelectorAll('.rowCheckbox:checked')).map(checkbox => checkbox.closest('tr'));
            if (selectedRows.includes(row)) {
                draggedRows = selectedRows;
            } else {
                draggedRows = [row];
            }
            draggedRows.forEach(draggedRow => {
                draggedRow.classList.add('dragging');
            });
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', 'dragging');
            console.log('Dragging rows:', draggedRows.map(r => r.dataset.id));
        });

        tbody.addEventListener('dragover', (e) => {
            e.preventDefault();
            const newTargetRow = e.target.closest('tr');
            if (!newTargetRow || draggedRows.includes(newTargetRow)) {
                console.log('Dragover event fired but invalid target row:', newTargetRow ? newTargetRow.dataset.id : 'null');
                return;
            }
            if (targetRow && targetRow !== newTargetRow) {
                targetRow.classList.remove('drag-over-highlight');
            }
            targetRow = newTargetRow;
            targetRow.classList.add('drag-over-highlight');
            const rect = targetRow.getBoundingClientRect();
            const midpoint = rect.top + rect.height / 2;
            const mouseY = e.clientY;
            if (mouseY < midpoint) {
                targetRow.classList.remove('drag-over-bottom');
                targetRow.classList.add('drag-over-top');
            } else {
                targetRow.classList.remove('drag-over-top');
                targetRow.classList.add('drag-over-bottom');
            }
            console.log('Dragover event fired, target row:', targetRow.dataset.id);
        });

        tbody.addEventListener('dragleave', (e) => {
            const leavingRow = e.target.closest('tr');
            if (leavingRow) {
                leavingRow.classList.remove('drag-over-top', 'drag-over-bottom', 'drag-over-highlight');
                console.log('Dragleave event fired for row:', leavingRow.dataset.id);
            } else {
                console.log('Dragleave event fired but no valid row:', e.target);
            }
        });

        tbody.addEventListener('drop', (e) => {
            e.preventDefault();
            if (!targetRow || draggedRows.includes(targetRow)) {
                console.log('Drop event fired but invalid target or dragged row:', targetRow ? targetRow.dataset.id : 'null');
                return;
            }
            const rect = targetRow.getBoundingClientRect();
            const midpoint = rect.top + rect.height / 2;
            const mouseY = e.clientY;
            const insertBefore = mouseY < midpoint;
            const fragment = document.createDocumentFragment();
            draggedRows.forEach(draggedRow => {
                fragment.appendChild(draggedRow);
            });
            if (insertBefore) {
                tbody.insertBefore(fragment, targetRow);
            } else {
                if (targetRow.nextSibling) {
                    tbody.insertBefore(fragment, targetRow.nextSibling);
                } else {
                    tbody.appendChild(fragment);
                }
            }
            targetRow.classList.remove('drag-over-top', 'drag-over-bottom', 'drag-over-highlight');
            console.log('Drop event fired, inserted rows before/after target row:', targetRow.dataset.id, 'insertBefore:', insertBefore);
            targetRow = null;
        });

        tbody.addEventListener('dragend', async (e) => {
            console.log('Dragend event fired for table:', tableId);
            draggedRows.forEach(draggedRow => {
                draggedRow.classList.remove('dragging');
            });

            if (targetRow) {
                targetRow.classList.remove('drag-over-highlight');
                targetRow = null;
            }

            const rows = Array.from(tbody.querySelectorAll('tr'));
            if (rows.length === 0) {
                console.log('No rows to reorder in table:', tableId);
                draggedRows = [];
                return;
            }

            const recordIds = rows.map(row => row.dataset.id).filter(id => {
                const isValid = !isNaN(parseInt(id)) && parseInt(id) > 0;
                if (!isValid) {
                    console.error(`Invalid record ID found: ${id}`);
                }
                return isValid;
            });
            if (recordIds.length === 0) {
                console.error('No valid record IDs to send for sort order update');
                alert('No valid records to reorder. Please refresh the page and try again.');
                draggedRows = [];
                return;
            }

            const tableName = tableMap[tableId];
            const urlParams = new URLSearchParams(window.location.search);
            const cacheBuster = urlParams.get('t') || Date.now();
            const uniqueTimestamp = Date.now();
            const currentPath = `${window.location.pathname}?id=${pageCategoryId}&t=${cacheBuster}&fetch_ts=${uniqueTimestamp}`;
            const requestBody = `action=update_sort_order&category_id=${pageCategoryId}&table=${tableName}&record_ids=${recordIds.join(',')}`;
            
            console.log('Preparing to send sort order update:', {
                action: 'update_sort_order',
                category_id: pageCategoryId,
                table: tableName,
                record_ids: recordIds,
                url: currentPath,
                requestBody: requestBody
            });

            console.log('Network status before fetch:', {
                online: navigator.onLine,
                connection: navigator.connection ? {
                    effectiveType: navigator.connection.effectiveType,
                    downlink: navigator.connection.downlink,
                    rtt: navigator.connection.rtt
                } : 'Connection API not supported'
            });

            try {
                const response = await fetchWithRetry(currentPath, {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Cache-Control': 'no-cache, no-store, must-revalidate'
                    },
                    body: requestBody,
                    redirect: 'manual',
                    keepalive: true
                });

                console.log('Sort order update response received:', {
                    status: response.status,
                    statusText: response.statusText,
                    type: response.type,
                    url: response.url,
                    redirected: response.redirected,
                    ok: response.ok,
                    headers: Array.from(response.headers.entries())
                });

                if (response.status >= 300 && response.status < 400) {
                    throw new Error(`Fetch request redirected with status: ${response.status}`);
                }
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status} ${response.statusText}`);
                }

                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                    console.log('Parsed JSON response:', data);
                } catch (jsonError) {
                    console.error('Failed to parse response as JSON:', text);
                    throw new Error(`Response is not valid JSON: ${text}`);
                }

                if (data.status !== 'success') {
                    console.error('Sort order update failed:', data.message);
                    alert('Failed to update sort order: ' + (data.message || 'Unknown error') + '. The order may revert on refresh. Retrying on next load.');
                    localStorage.setItem(`pendingSortOrder_${tableId}`, JSON.stringify({
                        url: currentPath,
                        options: {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'Cache-Control': 'no-cache, no-store, must-revalidate'
                            },
                            body: requestBody,
                            redirect: 'manual',
                            keepalive: true
                        }
                    }));
                } else {
                    console.log('Sort order updated successfully, updated rows:', data.updated);
                    localStorage.removeItem(`pendingSortOrder_${tableId}`);
                    setTimeout(() => {
                        window.location.reload();
                    }, 500);
                }
            } catch (error) {
                console.error('Error during sort order update:', {
                    message: error.message,
                    stack: error.stack,
                    navigatorOnline: navigator.onLine,
                    url: currentPath,
                    requestBody: requestBody
                });
                alert('An error occurred while updating sort order: ' + error.message + '. The order may revert on refresh. Retrying on next load.');
                localStorage.setItem(`pendingSortOrder_${tableId}`, JSON.stringify({
                    url: currentPath,
                    options: {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'Cache-Control': 'no-cache, no-store, must-revalidate'
                        },
                        body: requestBody,
                        redirect: 'manual',
                        keepalive: true
                    }
                }));
            } finally {
                console.log('Fetch attempt completed for sort order update');
            }

            draggedRows = [];
        });
    });

    const searchInput = document.getElementById('tableSearch');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const searchTerm = searchInput.value.toLowerCase();
            const tableId = pageCategoryId === 2 ? 'instrumentTable' : (pageCategoryId === 3 ? 'uniformTable' : (pageCategoryId === 4 ? 'musicTable' : 'rosterTable'));
            const table = document.getElementById(tableId);
            if (!table) {
                console.error(`Table with ID ${tableId} not found for search`);
                return;
            }
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                let rowContainsSearchTerm = false;
                cells.forEach(cell => {
                    if (cell.classList.contains('instrument-col-reorder') || 
                        cell.classList.contains('instrument-col-checkbox') ||
                        cell.classList.contains('uniform-col-reorder') || 
                        cell.classList.contains('uniform-col-checkbox') ||
                        cell.classList.contains('music-col-reorder') || 
                        cell.classList.contains('music-col-checkbox') ||
                        cell.classList.contains('roster-col-actions')) {
                        return;
                    }
                    if (cell.classList.contains('instrument-col-actions') ||
                        cell.classList.contains('uniform-col-actions') ||
                        cell.classList.contains('music-col-actions')) {
                        return;
                    }
                    const cellText = cell.textContent.toLowerCase();
                    if (cellText.includes(searchTerm)) {
                        rowContainsSearchTerm = true;
                    }
                });
                row.style.display = rowContainsSearchTerm || searchTerm === '' ? 'table-row' : 'none';
            });
            console.log(`Filtered table ${tableId} with search term: ${searchTerm}`);
        });
        console.log('Search input listener attached');
    }

    toggleEditDeleteButtons();

    // Retry any pending sort order updates on page load
    tables.forEach(tableId => {
        const pendingUpdate = localStorage.getItem(`pendingSortOrder_${tableId}`);
        if (pendingUpdate) {
            const { url, options } = JSON.parse(pendingUpdate);
            console.log(`Retrying pending sort order update for ${tableId}`, { url, options });
            fetchWithRetry(url, options)
                .then(response => response.text())
                .then(text => {
                    const data = JSON.parse(text);
                    if (data.status === 'success') {
                        console.log(`Pending sort order update for ${tableId} succeeded`, data);
                        localStorage.removeItem(`pendingSortOrder_${tableId}`);
                        window.location.reload();
                    } else {
                        console.error(`Pending sort order update for ${tableId} failed`, data);
                    }
                })
                .catch(error => {
                    console.error(`Failed to retry pending sort order update for ${tableId}`, error);
                });
        }
    });
}

function reinitializeEventListeners() {
    console.log('reinitializeEventListeners called at', new Date().toISOString());
    initializeEventListeners();
}

document.addEventListener('DOMContentLoaded', () => {
    console.log('DOMContentLoaded event fired at', new Date().toISOString());
    initializeEventListeners();
});

if (document.readyState === 'complete' || document.readyState === 'interactive') {
    console.log('Document already loaded, initializing immediately at', new Date().toISOString());
    initializeEventListeners();
}
</script>
</body>
</html>
<?php
error_log("category.php: Step 36 - Script completed at " . microtime(true));
?>


