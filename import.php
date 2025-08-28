<?php
error_log("Import.php: Script started at " . microtime(true));
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno] in $errfile on line $errline: $errstr");
    return true;
});
header('Content-Type: application/json');
require_once 'config.php';
$response = ['error' => null, 'headers' => [], 'fields' => [], 'sample_data' => [], 'data' => [], 'imported' => 0, 'skipped' => 0, 'errors' => []];

if (isset($_SESSION['session_failed']) && $_SESSION['session_failed']) {
    $response['error'] = 'Session failed to start due to server configuration issues';
    error_log("Import.php: Session failed to start, returning error response");
    echo json_encode($response);
    ob_end_flush();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['error'] = 'Invalid request method';
    error_log("Import.php: Invalid request method: {$_SERVER['REQUEST_METHOD']}");
    echo json_encode($response);
    ob_end_flush();
    exit;
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
error_log("Import.php: Action received: $action, Category ID: $category_id");

if ($action === 'parse') {
    if (!isset($_FILES['file'])) {
        $response['error'] = 'No file uploaded';
        error_log("Import.php: No file uploaded in \$_FILES");
        echo json_encode($response);
        ob_end_flush();
        exit;
    }
    error_log("Import.php: File upload details: " . json_encode($_FILES));
}

if ($action === 'parse') {
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error_codes = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        ];
        $upload_error = $_FILES['file']['error'] ?? 'Unknown error';
        $error_message = $error_codes[$upload_error] ?? 'Unknown upload error';
        $response['error'] = "File upload failed: $error_message (Code: $upload_error)";
        error_log("Import.php: File upload failed with error code: $upload_error - $error_message");
        echo json_encode($response);
        ob_end_flush();
        exit;
    }

    $file = $_FILES['file']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    $delimiter = $ext === 'tsv' ? "\t" : ',';

    $file_size = filesize($file);
    if ($file_size === 0) {
        $response['error'] = 'File is empty';
        error_log("Import.php: Uploaded file is empty: $file");
        echo json_encode($response);
        ob_end_flush();
        exit;
    }
    if ($file_size > 5 * 1024 * 1024) {
        $response['error'] = 'File size exceeds 5MB limit';
        error_log("Import.php: Uploaded file exceeds 5MB limit: $file_size bytes");
        echo json_encode($response);
        ob_end_flush();
        exit;
    }
    if (!is_readable($file)) {
        $response['error'] = 'File is not readable';
        error_log("Import.php: Cannot read uploaded file: $file");
        echo json_encode($response);
        ob_end_flush();
        exit;
    }

    $file_content = file_get_contents($file);
    $encoding = mb_detect_encoding($file_content, ['UTF-8', 'UTF-16', 'ISO-8859-1', 'Windows-1252'], true);
    if ($encoding === false) {
        $response['error'] = 'Unable to detect file encoding';
        error_log("Import.php: Unable to detect file encoding for: $file");
        echo json_encode($response);
        ob_end_flush();
        exit;
    }
    if ($encoding !== 'UTF-8') {
        $file_content = mb_convert_encoding($file_content, 'UTF-8', $encoding);
        if ($file_content === false) {
            $response['error'] = "Failed to convert file encoding from $encoding to UTF-8";
            error_log("Import.php: Failed to convert file encoding from $encoding to UTF-8 for: $file");
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $temp_file = tempnam(sys_get_temp_dir(), 'converted_');
        if (file_put_contents($temp_file, $file_content) === false) {
            $response['error'] = 'Failed to write converted file';
            error_log("Import.php: Failed to write converted file to: $temp_file");
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $file = $temp_file;
    }

    $bom = pack('H*', 'EFBBBF');
    if (strncmp($file_content, $bom, 3) === 0) {
        $file_content = substr($file_content, 3);
        if (file_put_contents($file, $file_content) === false) {
            $response['error'] = 'Failed to remove BOM from file';
            error_log("Import.php: Failed to remove BOM from file: $file");
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
    }

    $handle = @fopen($file, 'r');
    if ($handle === false) {
        $response['error'] = 'Failed to open file';
        error_log("Import.php: Failed to open file: $file");
        echo json_encode($response);
        ob_end_flush();
        exit;
    }

    $headers = fgetcsv($handle, 0, $delimiter);
    if ($headers === false || empty($headers)) {
        fclose($handle);
        $response['error'] = 'No headers found in file';
        error_log("Import.php: No headers found in file: $file");
        echo json_encode($response);
        ob_end_flush();
        exit;
    }

    $headers = array_map('trim', $headers);
    $empty_headers = array_filter($headers, function($header) {
        return empty($header);
    });
    if (!empty($empty_headers)) {
        fclose($handle);
        $response['error'] = 'Empty column names found in header row';
        error_log("Import.php: Empty column names found in header row: " . json_encode($headers));
        echo json_encode($response);
        ob_end_flush();
        exit;
    }

    $data = [];
    $row_count = 0;
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false && $row_count < 1000) {
        $row = array_map('trim', $row);
        if (count($row) < count($headers)) {
            $row = array_pad($row, count($headers), '');
        } elseif (count($row) > count($headers)) {
            $row = array_slice($row, 0, count($headers));
        }
        if (array_filter($row)) {
            $data[] = $row;
            $row_count++;
        }
    }
    fclose($handle);

    if ($encoding !== 'UTF-8' && isset($temp_file)) {
        @unlink($temp_file);
    }

    $response['headers'] = $headers;
    $response['data'] = $data;
    $response['total_rows'] = $row_count;

    $response['sample_data'] = array_slice($data, 0, 3);

    if ($category_id == 2) {
        $response['fields'] = ['instrument_type', 'brand', 'model', 'serial_no', 'asset_no', 'description', 'condition_notes'];
    } elseif ($category_id == 3) {
        $response['fields'] = ['item_type', 'item_no', 'size', 'inseam', 'waist', 'hips', 'notes'];
    } elseif ($category_id == 4) {
        $response['fields'] = ['library_no', 'title', 'composer', 'arranger', 'publisher', 'year', 'genre', 'difficulty', 'ensemble_type', 'last_performed'];
    } elseif ($category_id == 8) {
        $response['fields'] = ['last_name', 'first_name', 'grade', 'instrument', 'email', 'date_of_birth', 'address1', 'address2', 'city', 'state', 'zip'];
    } else {
        $response['error'] = 'Invalid category ID';
        error_log("Import.php: Invalid category ID: $category_id");
        echo json_encode($response);
        ob_end_flush();
        exit;
    }

    error_log("Import.php: Parsed file successfully, headers: " . json_encode($headers));
    echo json_encode($response);
    ob_end_flush();
    exit;
}

if ($action === 'import') {
    if (!isset($_POST['mappings']) || !isset($_POST['data'])) {
        $response['error'] = 'Missing mappings or data';
        error_log("Import.php: Missing mappings or data in import action");
        echo json_encode($response);
        ob_end_flush();
        exit;
    }

    $mappings = json_decode($_POST['mappings'], true);
    $data = json_decode($_POST['data'], true);
    if ($mappings === null || $data === null) {
        $response['error'] = 'Invalid JSON data for mappings or data';
        error_log("Import.php: Invalid JSON data - mappings: {$_POST['mappings']}, data: {$_POST['data']}");
        echo json_encode($response);
        ob_end_flush();
        exit;
    }

    if (!isset($_SESSION['user_id'])) {
        $response['error'] = 'User not logged in';
        error_log("Import.php: User not logged in during import action");
        echo json_encode($response);
        ob_end_flush();
        exit;
    }
    $user_id = $_SESSION['user_id'];

    $table = '';
    $fields = [];
    if ($category_id == 2) {
        $table = 'instrument_inventory';
        $fields = ['instrument_type', 'brand', 'model', 'serial_no', 'asset_no', 'description', 'condition_notes'];
    } elseif ($category_id == 3) {
        $table = 'uniform_inventory';
        $fields = ['item_type', 'item_no', 'size', 'inseam', 'waist', 'hips', 'notes'];
    } elseif ($category_id == 4) {
        $table = 'music_library';
        $fields = ['library_no', 'title', 'composer', 'arranger', 'publisher', 'year', 'genre', 'difficulty', 'ensemble_type', 'last_performed'];
    } elseif ($category_id == 8) {
        $table = 'roster';
        $fields = ['last_name', 'first_name', 'grade', 'instrument', 'email', 'date_of_birth', 'address1', 'address2', 'city', 'state', 'zip'];
    } else {
        $response['error'] = 'Invalid category ID';
        error_log("Import.php: Invalid category ID during import: $category_id");
        echo json_encode($response);
        ob_end_flush();
        exit;
    }

    $mapped_fields = [];
    $mapped_indices = [];
    foreach ($mappings as $column => $mapping) {
        if (in_array($mapping['field'], $fields)) {
            $mapped_fields[] = $mapping['field'];
            $mapped_indices[] = $mapping['index'];
        }
    }

    if (empty($mapped_fields)) {
        $response['error'] = 'No valid fields mapped';
        error_log("Import.php: No valid fields mapped: " . json_encode($mappings));
        echo json_encode($response);
        ob_end_flush();
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($mapped_fields), '?'));
    $query_fields = implode(',', $mapped_fields);
    $query = "INSERT INTO $table ($query_fields, user_id) VALUES ($placeholders, ?)";
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        $response['error'] = 'Failed to prepare SQL statement: ' . $mysqli->error;
        error_log("Import.php: Failed to prepare SQL statement for $table: " . $mysqli->error);
        echo json_encode($response);
        ob_end_flush();
        exit;
    }

    $total_rows = count($data);
    $response['total_rows'] = $total_rows;
    $row_number = 0;

    foreach ($data as $row) {
        $row_number++;
        $params = [];
        $types = str_repeat('s', count($mapped_indices));
        foreach ($mapped_indices as $index) {
            $value = isset($row[$index]) ? trim($row[$index]) : '';
            $params[] = $value;
        }
        $types .= 'i';
        $params[] = $user_id;
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            $response['imported']++;
        } else {
            $response['skipped']++;
            $response['errors'][] = "Row $row_number: " . $stmt->error;
            error_log("Import.php: Failed to import row $row_number into $table: " . $stmt->error);
        }
    }
    $stmt->close();

    error_log("Import.php: Import completed - Imported: {$response['imported']}, Skipped: {$response['skipped']}");
    echo json_encode($response);
    ob_end_flush();
    exit;
}

$response['error'] = 'Invalid action';
error_log("Import.php: Invalid action received: $action");
echo json_encode($response);
ob_end_flush();
exit;
?>
