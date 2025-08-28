<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_log("contact_information.php: Step 1 - Script started at " . microtime(true));
$request_uri = $_SERVER['REQUEST_URI'];
$request_id = md5($request_uri . microtime(true));
$_SESSION['current_request_id'] = $request_id;
unset($_SESSION['fixed_bar_rendered_' . $request_id]);
error_log("contact_information.php: Step 4 - Request ID set: " . $request_id);
if (isset($_SESSION['page_rendered_' . $request_id]) && $_SESSION['page_rendered_' . $request_id]) {
    error_log("contact_information.php: Step 5 - Already rendered for request_id: " . $request_id . ", exiting");
    session_write_close();
    exit;
}

error_log("contact_information.php: Step 6 - Proceeding with script");
require_once 'config.php';
error_log("contact_information.php: Step 7 - Config included at " . microtime(true));

if (!isset($_SESSION['user_id'])) {
    error_log("contact_information.php: Step 8 - No user logged in, redirecting to index.php");
    session_write_close();
    header('Location: index.php');
    exit;
}
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
error_log("contact_information.php: Step 9 - User logged in, ID: $user_id, Role: $role");

$category_id = 8; // Fixed for Contact Information (previously Roster)
$category_name = "Contact Information";

// Handle AJAX request to update sort order
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_sort_order') {
    $headers = getallheaders();
    error_log("contact_information.php: Received update_sort_order request with headers: " . json_encode($headers));
    $raw_input = file_get_contents('php://input');
    error_log("contact_information.php: Received update_sort_order request with raw input: " . $raw_input);
    error_log("contact_information.php: Received update_sort_order request with POST data: " . json_encode($_POST));
    
    $record_ids_input = isset($_POST['record_ids']) ? $_POST['record_ids'] : '';
    $posted_category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    $table = isset($_POST['table']) ? $_POST['table'] : '';
    $valid_tables = ['roster'];
    if (!in_array($table, $valid_tables)) {
        error_log("contact_information.php: Invalid table specified: $table");
        echo json_encode(['status' => 'error', 'message' => 'Invalid table specified']);
        session_write_close();
        exit;
    }
    $record_ids = !empty($record_ids_input) ? array_filter(array_map('intval', explode(',', $record_ids_input))) : [];
    if (empty($record_ids)) {
        error_log("contact_information.php: No record IDs provided for sort order update");
        echo json_encode(['status' => 'error', 'message' => 'No record IDs provided']);
        session_write_close();
        exit;
    }

    if ($posted_category_id !== $category_id) {
        error_log("contact_information.php: Category ID mismatch, expected $category_id, got $posted_category_id");
        echo json_encode(['status' => 'error', 'message' => 'Invalid category ID']);
        session_write_close();
        exit;
    }

    error_log("contact_information.php: Updating sort order for table: $table, category_id: $category_id, user_id: $user_id, record_ids: " . implode(',', $record_ids));

    $user_id_column = 'created_by_user_id';

    $result = $mysqli->query("SELECT id, $user_id_column, sort_order FROM $table WHERE $user_id_column = $user_id ORDER BY sort_order");
    if ($result) {
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        error_log("contact_information.php: Before update, $table contents: " . json_encode($rows));
        $result->free();
    } else {
        error_log("contact_information.php: Failed to fetch $table contents before update: " . $mysqli->error);
    }

    $success = true;
    $total_updated = 0;
    $stmt = $mysqli->prepare("UPDATE $table SET sort_order = ? WHERE id = ? AND $user_id_column = ?");
    if (!$stmt) {
        error_log("contact_information.php: Failed to prepare sort order update statement for $table: " . $mysqli->error);
        echo json_encode(['status' => 'error', 'message' => 'Failed to prepare statement: ' . $mysqli->error]);
        session_write_close();
        exit;
    }

    foreach ($record_ids as $index => $record_id) {
        $check_stmt = $mysqli->prepare("SELECT id FROM $table WHERE id = ? AND $user_id_column = ?");
        $check_stmt->bind_param('ii', $record_id, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows === 0) {
            error_log("contact_information.php: Record ID $record_id not found or $user_id_column mismatch in $table");
            continue;
        }
        $check_stmt->close();

        $stmt->bind_param('iii', $index, $record_id, $user_id);
        if ($stmt->execute()) {
            $affected_rows = $stmt->affected_rows;
            if ($affected_rows > 0) {
                $total_updated += $affected_rows;
                error_log("contact_information.php: Updated sort_order for record_id $record_id in $table to $index");
            } else {
                error_log("contact_information.php: No rows updated for record_id $record_id in $table (id may not exist or $user_id_column mismatch)");
            }
        } else {
            error_log("contact_information.php: Failed to update sort_order for record_id $record_id in $table: " . $stmt->error);
            $success = false;
        }
    }
    $stmt->close();

    $result = $mysqli->query("SELECT id, $user_id_column, sort_order FROM $table WHERE $user_id_column = $user_id ORDER BY sort_order");
    if ($result) {
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        error_log("contact_information.php: After update, $table contents: " . json_encode($rows));
        $result->free();
    } else {
        error_log("contact_information.php: Failed to fetch $table contents after update: " . $mysqli->error);
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
    $posted_category_id = (int)$_POST['category_id'];
    if ($posted_category_id !== $category_id) {
        error_log("contact_information.php: Category ID mismatch, expected $category_id, got $posted_category_id");
        session_write_close();
        header("Location: contact_information.php?id=$category_id");
        exit;
    }
    if (isset($_POST['record_ids']) && is_array($_POST['record_ids'])) {
        $record_ids = array_map('intval', $_POST['record_ids']);
        if (!empty($record_ids)) {
            $table = 'roster';
            $user_id_column = 'created_by_user_id';
            $placeholders = implode(',', array_fill(0, count($record_ids), '?'));
            $stmt = $mysqli->prepare("DELETE FROM $table WHERE id IN ($placeholders) AND $user_id_column = ?");
            if ($stmt) {
                $types = str_repeat('i', count($record_ids)) . 'i';
                $params = array_merge($record_ids, [$user_id]);
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) {
                    error_log("contact_information.php: Successfully deleted " . $stmt->affected_rows . " records from $table for $user_id_column: $user_id");
                } else {
                    error_log("contact_information.php: Failed to delete records from $table: " . $stmt->error);
                }
                $stmt->close();
            } else {
                error_log("contact_information.php: Failed to prepare batch delete statement: " . $mysqli->error);
            }
        }
    }
    session_write_close();
    header("Location: contact_information.php?id=$category_id&t=" . time());
    exit;
}

// Handle single record delete
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_record'])) {
    $record_id = (int)$_POST['record_id'];
    $posted_category_id = (int)$_POST['category_id'];
    if ($posted_category_id !== $category_id) {
        error_log("contact_information.php: Category ID mismatch for delete, expected $category_id, got $posted_category_id");
        session_write_close();
        header("Location: contact_information.php?id=$category_id");
        exit;
    }
    $table = 'roster';
    $user_id_column = 'created_by_user_id';
    $stmt = $mysqli->prepare("DELETE FROM $table WHERE id = ? AND $user_id_column = ?");
    $stmt->bind_param('ii', $record_id, $user_id);
    $stmt->execute();
    $stmt->close();
    session_write_close();
    header("Location: contact_information.php?id=$category_id&t=" . time());
    exit;
}

// Fetch roster records
$sort = isset($_GET['sort']) && in_array($_GET['sort'], [
    'last_name', 'first_name', 'grade', 'instrument', 'email', 'date_of_birth', 'address1', 'address2', 'city', 'state', 'zip', 'balance', 'associated_user_id'
]) ? $_GET['sort'] : 'sort_order';
$order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';
$query = "SELECT * FROM roster WHERE created_by_user_id = ?";
$numerical_columns = ['grade', 'balance', 'associated_user_id'];
if (in_array($sort, $numerical_columns)) {
    $query .= " ORDER BY CASE WHEN $sort IS NULL THEN 1 ELSE 0 END, CASE WHEN $sort REGEXP '^[0-9]+$' THEN CAST($sort AS UNSIGNED) ELSE 0 END $order";
} else if ($sort === 'sort_order') {
    $query .= " ORDER BY COALESCE(sort_order, 999999) $order, created_at ASC";
} else if ($sort === 'date_of_birth') {
    $query .= " ORDER BY $sort $order";
} else {
    $query .= " ORDER BY $sort $order";
}
$stmt = $mysqli->prepare($query);
if (!$stmt) {
    error_log("contact_information.php: Failed to prepare SELECT statement for roster: " . $mysqli->error);
    die("Failed to prepare database query for roster. Please check the server logs.");
}
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$roster_records = [];
while ($row = $result->fetch_assoc()) {
    $roster_records[] = $row;
}
$stmt->close();

$cache_buster = time();
$_SESSION['page_rendered_' . $request_id] = true;
error_log("contact_information.php: Step 35 - Page marked as rendered");
session_write_close();
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FireSafe - Contact Information</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        if (!window.location.search.includes('t=')) {
            window.location.href = window.location.pathname + '?id=<?php echo $category_id; ?>&t=<?php echo $cache_buster; ?>';
        }
    </script>

<style>
    .delete-button { padding: 8px 16px; background-color: #ff4444; color: white; border: none; border-radius: 5px; cursor: pointer; }
    .delete-button:hover { background-color: #cc0000; }
    .edit-button { padding: 8px 16px; background-color: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer; }
    .edit-button:hover { background-color: #45a049; }
    .main-container { 
        display: flex; 
        flex-wrap: wrap;
        width: 100%; 
        padding-top: 36px; 
    }
    .drag-handle { cursor: move; font-size: 16px; color: #666; }
    .drag-over { border: 2px dashed #000; background-color: #f0f0f0; }
    .drag-over-highlight { background-color: #d1e7ff; }
    tr.dragging { opacity: 0.5; background-color: #e0e0e0; }
    tr.selected { background-color: #e6f3ff; }
    #editDeleteButtons { display: none; }
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
        .fixed-bar { 
            left: 0;
        }
        .fixed-bar::before { 
            width: 100%;
        }
        .sidebar-offset { 
            margin-left: 0;
        }
        #formModal .modal-content {
            max-width: 90vw;
            padding: 0.5rem;
        }
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
    #rosterTable {
        table-layout: auto;
        border-collapse: collapse;
    }
    #rosterTable thead {
        position: sticky;
        top: 0px;
        z-index: 10;
        background-color: #ffffff;
        box-shadow: 0 2px 0 0 #e5e7eb;
    }
    #rosterTable th {
        border: 2px solid #e5e7eb;
        background-color: #ffffff;
        box-shadow: none;
    }
    .roster-col-reorder { min-width: 64px; width: auto; max-width: 159px; }
    .roster-col-checkbox { min-width: 110px; width: auto; max-width: 159px; }
    .roster-col-last-name { min-width: 72px; width: auto; max-width: 159px; }
    .roster-col-first-name { min-width: 72px; width: auto; max-width: 159px; }
    .roster-col-grade { min-width: 48px; width: auto; max-width: 159px; }
    .roster-col-instrument { min-width: 88px; width: auto; max-width: 159px; }
    .roster-col-email { min-width: 56px; width: auto; max-width: 159px; }
    .roster-col-date-of-birth { min-width: 80px; width: auto; max-width: 159px; }
    .roster-col-address1 { min-width: 72px; width: auto; max-width: 159px; }
    .roster-col-address2 { min-width: 72px; width: auto; max-width: 159px; }
    .roster-col-city { min-width: 48px; width: auto; max-width: 159px; }
    .roster-col-state { min-width: 56px; width: auto; max-width: 159px; }
    .roster-col-zip { min-width: 40px; width: auto; max-width: 159px; }
    .roster-col-balance { min-width: 72px; width: auto; max-width: 159px; }
    .roster-col-associated-user-id { min-width: 80px; width: auto; max-width: 159px; }
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
<div class="fixed-bar">
    <div class="flex items-center w-full">
        <div class="sidebar-offset flex items-center">
            <h2 class="text-xl font-bold text-gray-800 text-left"><?php echo htmlspecialchars($category_name); ?></h2>
            <div class="ml-4 flex items-center">
                <button onclick="openFormModal('roster_form.php')" class="inline-block bg-blue-600 text-white p-2 rounded-md hover:bg-blue-700 mr-2">Add New Record</button>
                <button onclick="openImportModal(<?php echo $category_id; ?>)" class="inline-block bg-green-600 text-white p-2 rounded-md hover:bg-green-700 mr-2">Import</button>
                <input type="text" id="tableSearch" placeholder="Search table..." class="p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
        </div>
    </div>
    <div id="editDeleteButtons" class="absolute right-4">
        <button id="editButton" class="edit-button mr-2" onclick="editSelected()">Edit Selected</button>
        <button id="deleteButton" class="delete-button" onclick="deleteSelected()">Delete Selected</button>
    </div>
</div>

<div class="main-container">
    <?php 
    session_start();
    if (!isset($_SESSION['sidebar_rendered_' . $request_id])) {
        include 'sidebar.php';
        $_SESSION['sidebar_rendered_' . $request_id] = true;
        error_log("contact_information.php: Sidebar rendered for request_id: " . $request_id);
    } else {
        error_log("contact_information.php: Sidebar already rendered for request_id: " . $request_id . ", skipping");
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
            <div class="content-container">
                <div class="table-scroll-wrapper">
                    <div class="overflow-x-auto">
<table class="min-w-full bg-white border" id="rosterTable">
    <thead>
        <tr>
            <th class="py-2 px-4 border text-center roster-col-reorder">Reorder</th>
            <th class="py-2 px-4 border text-center checkbox-header roster-col-checkbox"><input type="checkbox" id="selectAll"><br><span id="selectAllHeader">Select All</span></th>
            <th class="py-2 px-4 border text-center roster-col-last-name"><a href="contact_information.php?id=8&sort=last_name&order=<?php echo $sort === 'last_name' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Last Name <?php if ($sort === 'last_name') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
            <th class="py-2 px-4 border text-center roster-col-first-name"><a href="contact_information.php?id=8&sort=first_name&order=<?php echo $sort === 'first_name' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">First Name <?php if ($sort === 'first_name') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
            <th class="py-2 px-4 border text-center roster-col-grade"><a href="contact_information.php?id=8&sort=grade&order=<?php echo $sort === 'grade' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Grade <?php if ($sort === 'grade') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
            <th class="py-2 px-4 border text-center roster-col-instrument"><a href="contact_information.php?id=8&sort=instrument&order=<?php echo $sort === 'instrument' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Instrument <?php if ($sort === 'instrument') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
            <th class="py-2 px-4 border text-center roster-col-email"><a href="contact_information.php?id=8&sort=email&order=<?php echo $sort === 'email' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">E-mail <?php if ($sort === 'email') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
            <th class="py-2 px-4 border text-center roster-col-date-of-birth"><a href="contact_information.php?id=8&sort=date_of_birth&order=<?php echo $sort === 'date_of_birth' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Date of Birth <?php if ($sort === 'date_of_birth') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
            <th class="py-2 px-4 border text-center roster-col-address1"><a href="contact_information.php?id=8&sort=address1&order=<?php echo $sort === 'address1' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Address 1 <?php if ($sort === 'address1') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
            <th class="py-2 px-4 border text-center roster-col-address2"><a href="contact_information.php?id=8&sort=address2&order=<?php echo $sort === 'address2' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Address 2 <?php if ($sort === 'address2') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
            <th class="py-2 px-4 border text-center roster-col-city"><a href="contact_information.php?id=8&sort=city&order=<?php echo $sort === 'city' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">City <?php if ($sort === 'city') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
            <th class="py-2 px-4 border text-center roster-col-state"><a href="contact_information.php?id=8&sort=state&order=<?php echo $sort === 'state' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">State <?php if ($sort === 'state') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
            <th class="py-2 px-4 border text-center roster-col-zip"><a href="contact_information.php?id=8&sort=zip&order=<?php echo $sort === 'zip' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Zip <?php if ($sort === 'zip') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
            <th class="py-2 px-4 border text-center roster-col-balance"><a href="contact_information.php?id=8&sort=balance&order=<?php echo $sort === 'balance' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Balance <?php if ($sort === 'balance') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
            <th class="py-2 px-4 border text-center roster-col-associated-user-id"><a href="contact_information.php?id=8&sort=associated_user_id&order=<?php echo $sort === 'associated_user_id' && $order === 'ASC' ? 'desc' : 'asc'; ?>" class="text-blue-600 hover:underline" title="Sort By">Associated User ID <?php if ($sort === 'associated_user_id') echo $order === 'ASC' ? '↑' : '↓'; ?></a></th>
            <th class="py-2 px-4 border text-center roster-col-actions">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($roster_records as $record): ?>
            <tr data-id="<?php echo $record['id']; ?>">
                <td class="py-2 px-4 border text-center roster-col-reorder"><span class="drag-handle" draggable="true">☰</span></td>
                <td class="py-2 px-4 border text-center roster-col-checkbox"><input type="checkbox" class="rowCheckbox" value="<?php echo $record['id']; ?>"></td>
                <td class="py-2 px-4 border roster-col-last-name"><?php echo htmlspecialchars($record['last_name']); ?></td>
                <td class="py-2 px-4 border roster-col-first-name"><?php echo htmlspecialchars($record['first_name']); ?></td>
                <td class="py-2 px-4 border text-center roster-col-grade"><?php echo htmlspecialchars($record['grade']); ?></td>
                <td class="py-2 px-4 border roster-col-instrument"><?php echo htmlspecialchars($record['instrument']); ?></td>
                <td class="py-2 px-4 border roster-col-email"><?php echo htmlspecialchars($record['email']); ?></td>
                <td class="py-2 px-4 border text-center roster-col-date-of-birth"><?php echo htmlspecialchars($record['date_of_birth']); ?></td>
                <td class="py-2 px-4 border roster-col-address1"><?php echo htmlspecialchars($record['address1']); ?></td>
                <td class="py-2 px-4 border roster-col-address2"><?php echo htmlspecialchars($record['address2']); ?></td>
                <td class="py-2 px-4 border roster-col-city"><?php echo htmlspecialchars($record['city']); ?></td>
                <td class="py-2 px-4 border text-center roster-col-state"><?php echo htmlspecialchars($record['state']); ?></td>
                <td class="py-2 px-4 border text-center roster-col-zip"><?php echo htmlspecialchars($record['zip']); ?></td>
                <td class="py-2 px-4 border text-right roster-col-balance"><?php echo htmlspecialchars($record['balance'] !== null ? number_format($record['balance'], 2) : '0.00'); ?></td>
                <td class="py-2 px-4 border text-center roster-col-associated-user-id"><?php echo htmlspecialchars($record['associated_user_id'] ?: 'None'); ?></td>
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
    <p class="text-gray-600 mt-4">No contact information records found.</p>
<?php endif; ?>
<a href="home.php?t=<?php echo $cache_buster; ?>" class="mt-4 inline-block text-blue-600 hover:underline">Back to Home</a>
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
        editDeleteButtons.style.display = checkedCount > 0 ? 'block' : 'none';
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
    const formUrl = 'roster_form.php?record_ids=' + recordIds.join(',');
    openFormModal(formUrl);
    console.log('Opened edit modal with URL:', formUrl);
}

function deleteSelected() {
    console.log('deleteSelected called');
    const checkboxes = document.querySelectorAll('.rowCheckbox:checked');
    if (checkboxes.length === 0) return;
    if (!confirm('Are you sure you want to delete the selected records?')) return;
    const recordIds = Array.from(checkboxes).map(checkbox => checkbox.value);
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'contact_information.php?id=' + pageCategoryId;
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

function initializeEventListeners() {
    console.log('initializeEventListeners called');

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
        'rosterTable': 'roster'
    };

    const tables = ['rosterTable'];
    tables.forEach(tableId => {
        const table = document.getElementById(tableId);
        if (table) {
            const tbody = table.querySelector('tbody');
            let draggedRows = [];
            let targetRow = null;

            const dragHandles = tbody.querySelectorAll('.drag-handle');
            dragHandles.forEach(handle => {
                handle.setAttribute('draggable', 'true');
                handle.style.cursor = 'move';
                console.log(`Set draggable=true for drag handle in table ${tableId}`);
            });

            tbody.addEventListener('dragstart', (e) => {
                if (e.target.classList.contains('drag-handle')) {
                    const row = e.target.closest('tr');
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
                    console.log('Dragstart event fired for table:', tableId, 'Dragging rows:', draggedRows.map(r => r.dataset.id));
                } else {
                    console.log('Dragstart event fired but target is not a drag-handle:', e.target);
                }
            });

            tbody.addEventListener('dragover', (e) => {
                e.preventDefault();
                const newTargetRow = e.target.closest('tr');
                if (newTargetRow && !draggedRows.includes(newTargetRow)) {
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
                } else {
                    console.log('Dragover event fired but no valid target row:', newTargetRow ? newTargetRow.dataset.id : 'null');
                }
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
                    const response = await fetch(currentPath, {
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
                        alert('Failed to update sort order: ' + (data.message || 'Unknown error'));
                    } else {
                        console.log('Sort order updated successfully, updated rows:', data.updated);
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
                    alert('An error occurred while updating sort order: ' + error.message);
                } finally {
                    console.log('Fetch attempt completed for sort order update');
                }

                draggedRows = [];
            });
        } else {
            console.error(`Table with ID ${tableId} not found`);
        }
    });

    const searchInput = document.getElementById('tableSearch');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const searchTerm = searchInput.value.toLowerCase();
            const tableId = 'rosterTable';
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
                    if (cell.classList.contains('roster-col-reorder') || 
                        cell.classList.contains('roster-col-checkbox') ||
                        cell.classList.contains('roster-col-actions')) {
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
}

document.addEventListener('DOMContentLoaded', () => {
    console.log('DOMContentLoaded event fired');
    initializeEventListeners();
});

if (document.readyState === 'complete' || document.readyState === 'interactive') {
    console.log('Document already loaded, initializing immediately');
    initializeEventListeners();
}
</script>

</body>
</html>
<?php
error_log("contact_information.php: Step 36 - Script completed at " . microtime(true));
?>
