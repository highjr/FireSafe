<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_log("instrument_inventory_form.php: Step 1 - Script started at " . microtime(true));
$request_uri = $_SERVER['REQUEST_URI'];
$request_id = md5($request_uri . microtime(true));
$_SESSION['current_request_id'] = $request_id;
// Reset form_rendered for this request
unset($_SESSION['form_rendered_' . $request_id]);
error_log("instrument_inventory_form.php: Step 2 - Request ID set: " . $request_id);
if (isset($_SESSION['form_rendered_' . $request_id]) && $_SESSION['form_rendered_' . $request_id]) {
    error_log("instrument_inventory_form.php: Step 3 - Already rendered for request_id: " . $request_id . ", exiting");
    session_write_close();
    exit;
}

require_once 'config.php';
error_log("instrument_inventory_form.php: Step 4 - Config included at " . microtime(true));

// Check if $mysqli is properly initialized
if (!isset($mysqli) || $mysqli->connect_error) {
    error_log("instrument_inventory_form.php: Database connection failed: " . (isset($mysqli) ? $mysqli->connect_error : 'mysqli not set'));
    die("Database connection failed. Please check the server logs for details.");
}

// Log the MySQL host to confirm the connection
error_log("instrument_inventory_form.php: Connected to MySQL host: " . $mysqli->host_info);

if (!isset($_SESSION['user_id'])) {
    error_log("instrument_inventory_form.php: Step 5 - No user logged in, redirecting to index.php");
    session_write_close();
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$record_id = isset($_GET['record_id']) ? (int)$_GET['record_id'] : 0;
$record_ids = isset($_GET['record_ids']) ? array_map('intval', explode(',', $_GET['record_ids'])) : [];
$record = null;
$is_batch_edit = !empty($record_ids);

// Define instrument lists for all categories (except Custom, which starts empty)
$woodwinds_instruments = [
    'Piccolo', 'Flute', 'Oboe', 'English Horn',
    'Eb Clarinet', 'Bb Clarinet', 'Eb Alto Clarinet', 'Bb Bass Clarinet', 'Eb Contra Alto Clarinet', 'Bb Contra Bass Clarinet',
    'Bb Soprano Saxophone', 'Eb Alto Saxophone', 'Bb Tenor Saxophone', 'Eb Baritone Saxophone',
    'Bassoon'
];
$brass_instruments = [
    'Bb Cornet', 'Bb Trumpet', 'French Horn', 'Mellophone',
    'Trombone', 'Bass Trombone',
    'Baritone', 'Marching Baritone', 'Euphonium', 'Marching Euphonium',
    'Tuba', 'Sousaphone'
];
$percussion_instruments = [
    'Bells', 'Crotales', 'Vibraphone', 'Xylophone', 'Marimba', 'Chimes',
    'Piano', 'Digital Keyboard', 'Timpani',
    'Concert Snare Drum', 'Marching Snare Drum', 'Concert Toms', 'Tenor Drum(s)',
    'Concert Bass Drum', 'Marching Bass Drum'
];
$strings_instruments = [
    'Violin', 'Viola', 'Cello', 'Double Bass',
    'Guitar', 'Bass Guitar', 'Harp', 'Auto Harp'
];

// Map categories to their instrument lists (Custom is empty initially)
$categories = [
    'Woodwinds' => $woodwinds_instruments,
    'Brass' => $brass_instruments,
    'Percussion' => $percussion_instruments,
    'Strings' => $strings_instruments,
    'Custom' => [] // Empty list; users will add instruments via manage_instrument_types.php
];

// Ensure the category column can handle all categories
$result = $mysqli->query("SHOW COLUMNS FROM instrument_types LIKE 'category'");
if ($result && $row = $result->fetch_assoc()) {
    $column_type = $row['Type'];
    if (preg_match('/varchar\((\d+)\)/i', $column_type, $matches)) {
        $max_length = (int)$matches[1];
        $longest_category_length = max(array_map('strlen', array_keys($categories)));
        if ($longest_category_length > $max_length) {
            error_log("instrument_inventory_form.php: Attempting to increase category column length from $max_length to 50...");
            $alter_query = "ALTER TABLE instrument_types MODIFY COLUMN category VARCHAR(50)";
            if ($mysqli->query($alter_query)) {
                error_log("instrument_inventory_form.php: Successfully increased category column length to 50.");
            } else {
                error_log("instrument_inventory_form.php: Failed to increase category column length: " . $mysqli->error);
                $error_message = "Unable to set categories due to column length restrictions. Please contact your database administrator to increase the 'category' column length to at least 50 characters.";
            }
        }
    } elseif (preg_match('/enum\((.+)\)/i', $column_type, $matches)) {
        $enum_values = array_map('trim', explode(',', str_replace("'", "", $matches[1])));
        $missing_categories = array_diff(array_keys($categories), $enum_values);
        if (!empty($missing_categories)) {
            error_log("instrument_inventory_form.php: Missing categories in ENUM list: " . implode(', ', $missing_categories));
            // Attempt to add missing categories to the ENUM
            $new_enum = array_unique(array_merge($enum_values, array_keys($categories)));
            $new_enum_list = "'" . implode("','", $new_enum) . "'";
            $alter_query = "ALTER TABLE instrument_types MODIFY COLUMN category ENUM($new_enum_list)";
            if ($mysqli->query($alter_query)) {
                error_log("instrument_inventory_form.php: Successfully added missing categories to ENUM list: " . implode(', ', $missing_categories));
            } else {
                error_log("instrument_inventory_form.php: Failed to add categories to ENUM list: " . $mysqli->error);
                // Fallback: Convert to VARCHAR(50)
                $alter_query = "ALTER TABLE instrument_types MODIFY COLUMN category VARCHAR(50)";
                if ($mysqli->query($alter_query)) {
                    error_log("instrument_inventory_form.php: Converted category column to VARCHAR(50).");
                } else {
                    error_log("instrument_inventory_form.php: Failed to convert category to VARCHAR(50): " . $mysqli->error);
                    $error_message = "Unable to add categories (" . implode(', ', $missing_categories) . ") because they are not in the allowed list, and schema modification failed. Please contact your database administrator to add these categories to the 'category' column's ENUM or convert it to VARCHAR(50).";
                }
            }
        }
    }
    $result->free();
} else {
    error_log("instrument_inventory_form.php: Failed to check category column schema: " . $mysqli->error);
    $error_message = "Unable to verify the database schema for the 'category' column. Please contact your database administrator.";
}

// Ensure instruments exist for all categories (except Custom)
$missing_instruments = [];
foreach ($categories as $category => $instruments) {
    // Skip Custom since it should start empty
    if ($category === 'Custom') {
        continue;
    }

    $expected_count = count($instruments);
    $current_count = 0;
    $stmt = $mysqli->prepare('SELECT COUNT(*) FROM instrument_types WHERE category = ?');
    if ($stmt) {
        $stmt->bind_param('s', $category);
        $stmt->execute();
        $stmt->bind_result($current_count);
        $stmt->fetch();
        $stmt->close();
    } else {
        error_log("instrument_inventory_form.php: Failed to prepare COUNT statement for category '$category': " . $mysqli->error);
        $error_message = "Unable to verify instruments for category '$category'. Please contact your database administrator.";
        continue;
    }

    if ($current_count < $expected_count) {
        foreach ($instruments as $instrument) {
            $instrument = trim($instrument);
            error_log("instrument_inventory_form.php: Checking for instrument: '$instrument' in category: '$category'");

            // Check if the instrument exists
            $stmt = $mysqli->prepare('SELECT name, category FROM instrument_types WHERE TRIM(LOWER(name)) = TRIM(LOWER(?))');
            if ($stmt) {
                $stmt->bind_param('s', $instrument);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $db_name = $row['name'];
                    $db_category = $row['category'];
                    error_log("instrument_inventory_form.php: Found instrument in database: '$db_name' with category: '$db_category'");
                    if ($db_category !== $category) {
                        // Update category if it exists but is not correct
                        $update_stmt = $mysqli->prepare('UPDATE instrument_types SET category = ? WHERE TRIM(LOWER(name)) = TRIM(LOWER(?))');
                        if ($update_stmt) {
                            $update_stmt->bind_param('ss', $category, $instrument);
                            if ($update_stmt->execute()) {
                                $affected_rows = $update_stmt->affected_rows;
                                error_log("instrument_inventory_form.php: Updated category to '$category' for instrument: '$instrument', affected rows: $affected_rows");
                            } else {
                                error_log("instrument_inventory_form.php: Failed to execute UPDATE statement for instrument '$instrument': " . $update_stmt->error);
                                $missing_instruments[] = "$instrument (category: $category)";
                            }
                            $update_stmt->close();
                        } else {
                            error_log("instrument_inventory_form.php: Failed to prepare UPDATE statement for instrument '$instrument': " . $mysqli->error);
                            $missing_instruments[] = "$instrument (category: $category)";
                        }
                    }
                } else {
                    error_log("instrument_inventory_form.php: No match found for instrument: '$instrument'");
                    // Insert the instrument if it doesn't exist
                    $insert_stmt = $mysqli->prepare('INSERT INTO instrument_types (name, category, created_at) VALUES (?, ?, NOW())');
                    if ($insert_stmt) {
                        $insert_stmt->bind_param('ss', $instrument, $category);
                        if ($insert_stmt->execute()) {
                            error_log("instrument_inventory_form.php: Inserted instrument: '$instrument' into category: '$category'");
                        } else {
                            error_log("instrument_inventory_form.php: Failed to insert instrument '$instrument' into category '$category': " . $insert_stmt->error);
                            $missing_instruments[] = "$instrument (category: $category)";
                        }
                        $insert_stmt->close();
                    } else {
                        error_log("instrument_inventory_form.php: Failed to prepare INSERT statement for instrument '$instrument' in category '$category': " . $mysqli->error);
                        $missing_instruments[] = "$instrument (category: $category)";
                    }
                }
                $stmt->close();
            } else {
                error_log("instrument_inventory_form.php: Failed to prepare SELECT statement for checking instrument '$instrument' in category '$category': " . $mysqli->error);
                $missing_instruments[] = "$instrument (category: $category)";
            }
        }
    }
}

// If any instruments failed to be added, set an error message
if (!empty($missing_instruments)) {
    $error_message = "Failed to add the following instruments: " . implode(', ', $missing_instruments) . ". Please contact your database administrator to resolve this issue.";
}

// Fetch instrument types for dropdown, grouped by category
$stmt = $mysqli->prepare('SELECT name, COALESCE(category, "") AS category FROM instrument_types ORDER BY category, name');
if (!$stmt) {
    error_log("instrument_inventory_form.php: Failed to prepare SELECT statement for instrument types: " . $mysqli->error);
    die("Failed to fetch instrument types. Please check the server logs.");
}
$stmt->execute();
$result = $stmt->get_result();
$instrument_types = [];
while ($row = $result->fetch_assoc()) {
    $category = $row['category'];
    if (!isset($instrument_types[$category])) {
        $instrument_types[$category] = [];
    }
    $instrument_types[$category][] = $row['name'];
}
$stmt->close();

// Debug: Log the fetched instrument types
error_log("instrument_inventory_form.php: Fetched instrument types: " . json_encode($instrument_types));

// Define score order for each category
$woodwinds_score_order = [
    'Piccolo', 'Flute', 'Oboe', 'English Horn',
    'Eb Clarinet', 'Bb Clarinet', 'Eb Alto Clarinet', 'Bb Bass Clarinet', 'Eb Contra Alto Clarinet', 'Bb Contra Bass Clarinet',
    'Bb Soprano Saxophone', 'Eb Alto Saxophone', 'Bb Tenor Saxophone', 'Eb Baritone Saxophone',
    'Bassoon'
];
$brass_score_order = [
    'Bb Cornet', 'Bb Trumpet', 'French Horn', 'Mellophone',
    'Trombone', 'Bass Trombone',
    'Baritone', 'Marching Baritone', 'Euphonium', 'Marching Euphonium',
    'Tuba', 'Sousaphone'
];
$strings_score_order = [
    'Violin', 'Viola', 'Cello', 'Double Bass',
    'Guitar', 'Bass Guitar', 'Harp', 'Auto Harp'
];
$percussion_score_order = [
    'Bells', 'Crotales', 'Vibraphone', 'Xylophone', 'Marimba', 'Chimes',
    'Piano', 'Digital Keyboard', 'Timpani',
    'Concert Snare Drum', 'Marching Snare Drum', 'Concert Toms', 'Tenor Drum(s)',
    'Concert Bass Drum', 'Marching Bass Drum'
];

// Reorder instrument types according to desired category order
$desired_category_order = ['Woodwinds', 'Brass', 'Percussion', 'Strings', 'Custom'];
$ordered_instrument_types = [];
foreach ($desired_category_order as $category) {
    if (isset($instrument_types[$category])) {
        $ordered_instrument_types[$category] = [];
        if ($category === 'Woodwinds') {
            foreach ($woodwinds_score_order as $type) {
                if (in_array($type, $instrument_types[$category])) {
                    $ordered_instrument_types[$category][] = $type;
                }
            }
            $remaining = array_diff($instrument_types[$category], $woodwinds_score_order);
            sort($remaining);
            $ordered_instrument_types[$category] = array_merge($ordered_instrument_types[$category], $remaining);
        } elseif ($category === 'Brass') {
            foreach ($brass_score_order as $type) {
                if (in_array($type, $instrument_types[$category])) {
                    $ordered_instrument_types[$category][] = $type;
                }
            }
            $remaining = array_diff($instrument_types[$category], $brass_score_order);
            sort($remaining);
            $ordered_instrument_types[$category] = array_merge($ordered_instrument_types[$category], $remaining);
        } elseif ($category === 'Strings') {
            foreach ($strings_score_order as $type) {
                if (in_array($type, $instrument_types[$category])) {
                    $ordered_instrument_types[$category][] = $type;
                }
            }
            $remaining = array_diff($instrument_types[$category], $strings_score_order);
            sort($remaining);
            $ordered_instrument_types[$category] = array_merge($ordered_instrument_types[$category], $remaining);
        } elseif ($category === 'Percussion') {
            foreach ($percussion_score_order as $type) {
                if (in_array($type, $instrument_types[$category])) {
                    $ordered_instrument_types[$category][] = $type;
                }
            }
            $remaining = array_diff($instrument_types[$category], $percussion_score_order);
            sort($remaining);
            $ordered_instrument_types[$category] = array_merge($ordered_instrument_types[$category], $remaining);
        } else {
            $ordered_instrument_types[$category] = $instrument_types[$category];
            sort($ordered_instrument_types[$category]);
        }
    }
}

if ($record_id) {
    $stmt = $mysqli->prepare('SELECT * FROM instrument_inventory WHERE id = ? AND user_id = ?');
    if (!$stmt) {
        error_log("instrument_inventory_form.php: Failed to prepare SELECT statement: " . $mysqli->error);
        die("Failed to prepare database query. Please check the server logs.");
    }
    $stmt->bind_param('ii', $record_id, $user_id);
    if (!$stmt->execute()) {
        error_log("instrument_inventory_form.php: Failed to execute SELECT statement: " . $stmt->error);
        die("Failed to execute database query. Please check the server logs.");
    }
    $result = $stmt->get_result();
    $record = $result->fetch_assoc();
    $stmt->close();
} elseif ($is_batch_edit) {
    $placeholders = implode(',', array_fill(0, count($record_ids), '?'));
    $stmt = $mysqli->prepare("SELECT * FROM instrument_inventory WHERE id IN ($placeholders) AND user_id = ?");
    if (!$stmt) {
        error_log("instrument_inventory_form.php: Failed to prepare batch SELECT statement: " . $mysqli->error);
        die("Failed to prepare batch database query. Please check the server logs.");
    }
    $types = str_repeat('i', count($record_ids)) . 'i';
    $params = array_merge($record_ids, [$user_id]);
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        error_log("instrument_inventory_form.php: Failed to execute batch SELECT statement: " . $stmt->error);
        die("Failed to execute batch database query. Please check the server logs.");
    }
    $result = $stmt->get_result();
    $records = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['instrument_type', 'brand', 'model', 'serial_no', 'asset_no', 'description', 'condition_notes'];
    $data = [];
    foreach ($fields as $field) {
        $data[$field] = isset($_POST[$field]) ? trim($_POST[$field]) : '';
    }

    if ($record_id) {
        $query = 'UPDATE instrument_inventory SET instrument_type = ?, brand = ?, model = ?, serial_no = ?, asset_no = ?, description = ?, condition_notes = ? WHERE id = ? AND user_id = ?';
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            error_log("instrument_inventory_form.php: Failed to prepare UPDATE statement: " . $mysqli->error);
            die("Failed to prepare update query. Please check the server logs.");
        }
        $stmt->bind_param('sssssssii', $data['instrument_type'], $data['brand'], $data['model'], $data['serial_no'], $data['asset_no'], $data['description'], $data['condition_notes'], $record_id, $user_id);
        if ($stmt->execute()) {
            session_write_close();
            echo '<script>window.parent.location.href="category.php?id=2&t=' . time() . '"; window.parent.closeFormModal();</script>';
            exit;
        } else {
            error_log("instrument_inventory_form.php: Failed to execute UPDATE statement: " . $stmt->error);
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    } elseif ($is_batch_edit) {
        foreach ($record_ids as $id) {
            $set_clause = [];
            $params = [];
            $types = '';
            foreach ($fields as $field) {
                if ($data[$field] !== '{No change}') {
                    $set_clause[] = "$field = ?";
                    $params[] = $data[$field];
                    $types .= 's';
                }
            }
            if (!empty($set_clause)) {
                $query = 'UPDATE instrument_inventory SET ' . implode(', ', $set_clause) . ' WHERE id = ? AND user_id = ?';
                $stmt = $mysqli->prepare($query);
                if (!$stmt) {
                    error_log("instrument_inventory_form.php: Failed to prepare batch UPDATE statement: " . $mysqli->error);
                    die("Failed to prepare batch update query. Please check the server logs.");
                }
                $types .= 'ii';
                $params[] = $id;
                $params[] = $user_id;
                $stmt->bind_param($types, ...$params);
                if (!$stmt->execute()) {
                    error_log("instrument_inventory_form.php: Failed to execute batch UPDATE statement for ID $id: " . $stmt->error);
                    echo "Error updating record ID $id: " . $stmt->error;
                    $stmt->close();
                    exit;
                }
                $stmt->close();
            }
        }
        session_write_close();
        echo '<script>window.parent.location.href="category.php?id=2&t=' . time() . '"; window.parent.closeFormModal();</script>';
        exit;
    } else {
        $query = 'INSERT INTO instrument_inventory (instrument_type, brand, model, serial_no, asset_no, description, condition_notes, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            error_log("instrument_inventory_form.php: Failed to prepare INSERT statement: " . $mysqli->error);
            die("Failed to prepare insert query. Please check the server logs.");
        }
        $stmt->bind_param('sssssssi', $data['instrument_type'], $data['brand'], $data['model'], $data['serial_no'], $data['asset_no'], $data['description'], $data['condition_notes'], $user_id);
        if ($stmt->execute()) {
            session_write_close();
            echo '<script>window.parent.location.href="category.php?id=2&t=' . time() . '"; window.parent.closeFormModal();</script>';
            exit;
        } else {
            error_log("instrument_inventory_form.php: Failed to execute INSERT statement: " . $stmt->error);
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    }
}

$_SESSION['form_rendered_' . $request_id] = true;
error_log("instrument_inventory_form.php: Step 6 - Form marked as rendered");
session_write_close();
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FireSafe - Instrument Inventory Form</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .form-container:not(:first-of-type) {
            display: none !important;
        }
        .form-container {
            padding: 0.25rem;
        }
        .button-group {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            align-items: center;
            margin-top: 0.5rem;
            margin-bottom: 0.25rem;
        }
        .cancel-button, .save-button {
            width: 100px;
            height: 40px;
            padding: 0;
            border: 2px solid #2563eb;
            border-radius: 0.375rem;
            font-size: 1rem;
            line-height: 36px;
            text-align: center;
            transition: background-color 0.2s, color 0.2s;
        }
        .cancel-button {
            color: #2563eb;
            background-color: transparent;
        }
        .cancel-button:hover {
            background-color: #2563eb;
            color: white;
        }
        .save-button {
            color: white;
            background-color: #2563eb;
        }
        .save-button:hover {
            background-color: #1d4ed8;
        }
        .error-message {
            color: red;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body class="bg-white min-h-screen">
    <div class="form-container">
        <h2 class="text-xl font-bold mb-1"><?php echo $record_id ? 'Edit' : ($is_batch_edit ? 'Batch Edit' : 'Add'); ?> Instrument Inventory Record</h2>
        <?php if (isset($error_message)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        <form method="POST" action="" class="bg-white rounded-lg">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Instrument Type</label>
                    <select name="instrument_type" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                        <?php if ($is_batch_edit): ?>
                            <option value="{No change}" selected>{No change}</option>
                        <?php else: ?>
                            <option value="" <?php echo !isset($record['instrument_type']) ? 'selected' : ''; ?>>Select Instrument Type</option>
                        <?php endif; ?>
                        <?php foreach ($ordered_instrument_types as $category => $types): ?>
                            <optgroup label="<?php echo htmlspecialchars($category); ?>">
                                <?php foreach ($types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo isset($record['instrument_type']) && $record['instrument_type'] === $type ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
<?php if (!$is_batch_edit): ?>
    <button type="button" onclick="loadManageTypes()" class="block mt-1 text-blue-600 hover:underline text-sm focus:outline-none">Add Custom Type</button>
<?php endif; ?>
</div>
<div>
    <label class="block text-sm font-medium text-gray-700">Brand</label>
    <input type="text" name="brand" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['brand'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
</div>
<div>
    <label class="block text-sm font-medium text-gray-700">Model</label>
    <input type="text" name="model" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['model'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
</div>
<div>
    <label class="block text-sm font-medium text-gray-700">Serial No.</label>
    <input type="text" name="serial_no" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['serial_no'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
</div>
<div>
    <label class="block text-sm font-medium text-gray-700">Asset No.</label>
    <input type="text" name="asset_no" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['asset_no'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
</div>
<div>
    <label class="block text-sm font-medium text-gray-700">Description</label>
    <input type="text" name="description" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['description'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
</div>
<div>
    <label class="block text-sm font-medium text-gray-700">Condition/Notes</label>
    <input type="text" name="condition_notes" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['condition_notes'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
</div>
</div>
<div class="button-group">
    <button type="button" id="cancelButton" class="cancel-button">Cancel</button>
    <button type="submit" class="save-button">Save</button>
</div>
</form>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    console.log('instrument_inventory_form.php: DOMContentLoaded event fired at', new Date().toISOString());

    setTimeout(() => {
        console.log('Checking for duplicate form containers...');
        const formContainers = document.querySelectorAll('.form-container');
        console.log(`Found ${formContainers.length} form containers`);
        if (formContainers.length > 1) {
            console.log('Removing duplicate form containers');
            for (let i = 1; i < formContainers.length; i++) {
                formContainers[i].remove();
            }
        }
        const finalFormContainers = document.querySelectorAll('.form-container');
        console.log(`Final state: ${finalFormContainers.length} form containers`);
    }, 500);

    // Function to attempt closing the modal with enhanced fallback
    const attemptCloseModal = () => {
        console.log('Attempting to close modal at', new Date().toISOString());
        try {
            if (typeof window.parent.closeFormModal === 'function') {
                window.parent.closeFormModal();
                console.log('Successfully called window.parent.closeFormModal');
            } else {
                console.warn('window.parent.closeFormModal is not a function, attempting fallback');
                // Fallback: Directly hide the modal by accessing the parent DOM
                const parentDoc = window.parent.document;
                const formModal = parentDoc.getElementById('formModal');
                if (formModal) {
                    formModal.classList.add('hidden');
                    console.log('Fallback: Modal hidden by adding hidden class');
                    // Reset iframe content
                    const formIframe = parentDoc.getElementById('formIframe');
                    if (formIframe) {
                        formIframe.src = '';
                        formIframe.style.height = '';
                    }
                    const modalContent = parentDoc.querySelector('#formModal .modal-content');
                    if (modalContent) {
                        modalContent.style.minHeight = '275px';
                    }
                } else {
                    console.error('Fallback failed: formModal not found in parent DOM');
                    // Last resort: Navigate parent to refresh the page without saving
                    window.parent.location.href = "category.php?id=2&t=" + Date.now();
                    console.log('Last resort: Navigated parent to refresh page');
                }
            }
        } catch (error) {
            console.error('Error in attemptCloseModal:', error.message, error.stack);
            // Fallback in case of error: Navigate parent to refresh
            try {
                window.parent.location.href = "category.php?id=2&t=" + Date.now();
                console.log('Error fallback: Navigated parent to refresh page');
            } catch (navError) {
                console.error('Navigation fallback failed:', navError.message);
                alert('Unable to close the modal due to an error. The page will now refresh.');
                window.location.reload();
            }
        }
    };

    // Cancel button event listener
    const cancelButton = document.getElementById('cancelButton');
    if (cancelButton) {
        cancelButton.addEventListener('click', (e) => {
            e.preventDefault();
            console.log('Cancel button clicked at', new Date().toISOString());
            attemptCloseModal();
        });
        console.log('Cancel button listener attached');
    } else {
        console.error('Cancel button not found in DOM');
    }

    // Esc key event listener
    document.addEventListener('keydown', (e) => {
        console.log('Keydown event captured:', e.key, 'KeyCode:', e.keyCode);
        if (e.key === 'Escape' || e.keyCode === 27) {
            e.preventDefault();
            console.log('Escape key pressed at', new Date().toISOString());
            attemptCloseModal();
        } else if (e.key === 'Enter' || e.keyCode === 13) {
            if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'SELECT') {
                e.preventDefault();
                console.log('Enter key pressed, triggering save');
                document.querySelector('.save-button').click();
            }
        }
    });
    console.log('Keydown listener attached for Esc and Enter keys');

    function loadManageTypes() {
        window.parent.document.querySelector('iframe').src = 'manage_instrument_types.php';
    }
});
</script>
</body>
</html>
<?php
error_log("instrument_inventory_form.php: Step 7 - Script completed at " . microtime(true));
?>
