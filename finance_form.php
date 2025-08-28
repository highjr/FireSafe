<?php
require_once 'config.php';
error_log("finance_form.php: Script started for user_id: " . $_SESSION['user_id']);

$user_id = $_SESSION['user_id'];
$record_id = isset($_GET['record_id']) ? (int)$_GET['record_id'] : 0;
$record_ids = isset($_GET['record_ids']) ? explode(',', $_GET['record_ids']) : [];
$mode = $record_id ? 'edit' : (count($record_ids) > 1 ? 'bulk_edit' : 'add');

$record = [];
if ($record_id) {
    $stmt = $mysqli->prepare("SELECT * FROM finances WHERE id = ? AND user_id = ?");
    if ($stmt) {
        $stmt->bind_param('ii', $record_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $record = $result->fetch_assoc();
        $stmt->close();
        if (!$record) {
            error_log("finance_form.php: Record ID $record_id not found for user_id $user_id");
            die("Record not found.");
        }
    } else {
        error_log("finance_form.php: Failed to prepare SELECT statement: " . $mysqli->error);
        die("Database error.");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'] ?? date('Y-m-d');
    $description = $_POST['description'] ?? '';
    $amount = $_POST['amount'] ?? 0.00;
    $type = $_POST['type'] ?? 'receivable';

    if ($mode === 'add' || $mode === 'edit') {
        if ($record_id) {
            $stmt = $mysqli->prepare("UPDATE finances SET date = ?, description = ?, amount = ?, type = ? WHERE id = ? AND user_id = ?");
            $stmt->bind_param('ssdsi', $date, $description, $amount, $type, $record_id, $user_id);
            error_log("finance_form.php: Updating record ID $record_id");
        } else {
            $stmt = $mysqli->prepare("INSERT INTO finances (user_id, date, description, amount, type) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('issds', $user_id, $date, $description, $amount, $type);
            error_log("finance_form.php: Inserting new record for user_id $user_id");
        }
        if ($stmt->execute()) {
            error_log("finance_form.php: Record saved successfully");
            header("Location: category.php?id=13&t=" . time());
            exit;
        } else {
            error_log("finance_form.php: Failed to save record: " . $stmt->error);
        }
        $stmt->close();
    } elseif ($mode === 'bulk_edit' && !empty($record_ids)) {
        $mysqli->begin_transaction();
        try {
            $stmt = $mysqli->prepare("UPDATE finances SET date = ?, description = ?, amount = ?, type = ? WHERE id = ? AND user_id = ?");
            foreach ($record_ids as $id) {
                $stmt->bind_param('ssdsi', $date, $description, $amount, $type, $id, $user_id);
                if (!$stmt->execute()) {
                    error_log("finance_form.php: Failed to update record ID $id: " . $stmt->error);
                    throw new Exception("Update failed for ID $id");
                }
            }
            $mysqli->commit();
            error_log("finance_form.php: Bulk update successful for " . count($record_ids) . " records");
            header("Location: category.php?id=13&t=" . time());
            exit;
        } catch (Exception $e) {
            $mysqli->rollback();
            error_log("finance_form.php: Bulk update failed: " . $e->getMessage());
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FireSafe - <?php echo $mode === 'edit' ? 'Edit' : 'Add'; ?> Financial Record</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .modal-content { 
            width: 750px; 
            min-height: 450px; 
            margin: 0 auto; 
            background-color: #fff;
            border-radius: 0.5rem;
            padding: 1rem;
            overflow-x: hidden; /* Prevent horizontal scrollbar */
            box-sizing: border-box; /* Include padding in width */
            transition: min-height 0.3s ease;
        }
        /* Constrain form elements */
        input, select {
            max-width: 100%; /* Prevent inputs from exceeding container */
        }
        /* Fix button row overflow */
        .flex.justify-end {
            flex-wrap: wrap; /* Allow wrapping if needed */
            gap: 0.5rem; /* Space between buttons */
            max-width: 100%; /* Limit to container width */
        }
        button {
            flex: 0 0 auto; /* Prevent stretching */
            white-space: nowrap; /* Prevent text wrapping */
            max-width: 100%; /* Cap button width */
        }
        /* Ensure body doesn’t add extra width */
        body {
            margin: 0;
            padding: 0;
            width: 100%;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="modal-content">
        <h2 class="text-2xl font-bold mb-4"><?php echo ucfirst($mode); ?> Financial Record</h2>
        <form method="POST" action="">
            <div class="mb-4">
                <label for="date" class="block text-sm font-medium text-gray-700">Date</label>
                <input type="date" name="date" id="date" value="<?php echo htmlspecialchars($record['date'] ?? date('Y-m-d')); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
            </div>
            <div class="mb-4">
                <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                <input type="text" name="description" id="description" value="<?php echo htmlspecialchars($record['description'] ?? ''); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
            </div>
            <div class="mb-4">
                <label for="amount" class="block text-sm font-medium text-gray-700">Amount</label>
                <input type="number" step="0.01" name="amount" id="amount" value="<?php echo htmlspecialchars($record['amount'] ?? '0.00'); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
            </div>
            <div class="mb-4">
                <label for="type" class="block text-sm font-medium text-gray-700">Type</label>
                <select name="type" id="type" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                    <option value="receivable" <?php echo ($record['type'] ?? 'receivable') === 'receivable' ? 'selected' : ''; ?>>Receivable</option>
                    <option value="payable" <?php echo ($record['type'] ?? 'receivable') === 'payable' ? 'selected' : ''; ?>>Payable</option>
                </select>
            </div>
            <div class="flex justify-end">
                <button type="button" onclick="window.parent.closeFormModal()" class="mr-2 px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"><?php echo ucfirst($mode); ?></button>
            </div>
        </form>
    </div>
</body>
</html>
