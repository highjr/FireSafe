<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_log("roster_form.php: Step 1 - Script started at " . microtime(true));
$request_uri = $_SERVER['REQUEST_URI'];
$request_id = md5($request_uri . microtime(true));
$_SESSION['current_request_id'] = $request_id;
unset($_SESSION['form_rendered_' . $request_id]);
error_log("roster_form.php: Step 2 - Request ID set: " . $request_id);
if (isset($_SESSION['form_rendered_' . $request_id]) && $_SESSION['form_rendered_' . $request_id]) {
    error_log("roster_form.php: Step 3 - Already rendered for request_id: " . $request_id . ", exiting");
    session_write_close();
    exit;
}

require_once 'config.php';
error_log("roster_form.php: Step 4 - Config included at " . microtime(true));

if (!isset($_SESSION['user_id'])) {
    error_log("roster_form.php: Step 5 - No user logged in, redirecting to index.php");
    session_write_close();
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$record_id = isset($_GET['record_id']) ? (int)$_GET['record_id'] : 0;
$record_ids = isset($_GET['record_ids']) ? array_map('intval', explode(',', $_GET['record_ids'])) : [];
$record = null;
$is_batch_edit = !empty($record_ids);

// Fetch users for the associated_user_id dropdown (exclude Director and Admin roles)
$users = [];
$user_stmt = $mysqli->prepare("SELECT id, name, role FROM users WHERE role IN ('Student', 'Booster', 'Supervisory') ORDER BY name");
$user_stmt->execute();
$result = $user_stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$user_stmt->close();

// Fetch the current record if editing a single record
if ($record_id) {
    $stmt = $mysqli->prepare('SELECT * FROM roster WHERE id = ? AND created_by_user_id = ?');
    $stmt->bind_param('ii', $record_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $record = $result->fetch_assoc();
    $stmt->close();
} elseif ($is_batch_edit) {
    $placeholders = implode(',', array_fill(0, count($record_ids), '?'));
    $stmt = $mysqli->prepare("SELECT * FROM roster WHERE id IN ($placeholders) AND created_by_user_id = ?");
    $types = str_repeat('i', count($record_ids)) . 'i';
    $params = array_merge($record_ids, [$user_id]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['last_name', 'first_name', 'grade', 'instrument', 'email', 'date_of_birth', 'address1', 'address2', 'city', 'state', 'zip', 'associated_user_id'];
    $data = [];
    foreach ($fields as $field) {
        if ($field === 'associated_user_id') {
            $data[$field] = isset($_POST[$field]) && $_POST[$field] !== '' ? (int)$_POST[$field] : null;
        } else {
            $data[$field] = isset($_POST[$field]) ? trim($_POST[$field]) : '';
        }
    }

    if ($record_id) {
        // Update single record
        $query = 'UPDATE roster SET last_name = ?, first_name = ?, grade = ?, instrument = ?, email = ?, date_of_birth = ?, address1 = ?, address2 = ?, city = ?, state = ?, zip = ?, associated_user_id = ? WHERE id = ? AND created_by_user_id = ?';
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param('sssssssssssiii', $data['last_name'], $data['first_name'], $data['grade'], $data['instrument'], $data['email'], $data['date_of_birth'], $data['address1'], $data['address2'], $data['city'], $data['state'], $data['zip'], $data['associated_user_id'], $record_id, $user_id);
        if ($stmt->execute()) {
            session_write_close();
            echo '<script>window.parent.location.href="category.php?id=8&t=' . time() . '"; window.parent.closeFormModal();</script>';
            exit;
        } else {
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    } elseif ($is_batch_edit) {
        foreach ($record_ids as $id) {
            $set_clause = [];
            $params = [];
            $types = '';
            foreach ($fields as $field) {
                if ($field === 'associated_user_id') {
                    if ($data[$field] !== null && $data[$field] !== '{No change}') {
                        $set_clause[] = "$field = ?";
                        $params[] = $data[$field];
                        $types .= 'i';
                    }
                } else {
                    if ($data[$field] !== '{No change}') {
                        $set_clause[] = "$field = ?";
                        $params[] = $data[$field];
                        $types .= 's';
                    }
                }
            }
            if (!empty($set_clause)) {
                $query = 'UPDATE roster SET ' . implode(', ', $set_clause) . ' WHERE id = ? AND created_by_user_id = ?';
                $stmt = $mysqli->prepare($query);
                $types .= 'ii';
                $params[] = $id;
                $params[] = $user_id;
                $stmt->bind_param($types, ...$params);
                if (!$stmt->execute()) {
                    echo "Error updating record ID $id: " . $stmt->error;
                    $stmt->close();
                    exit;
                }
                $stmt->close();
            }
        }
        session_write_close();
        echo '<script>window.parent.location.href="category.php?id=8&t=' . time() . '"; window.parent.closeFormModal();</script>';
        exit;
    } else {
        // Insert new record
        $query = 'INSERT INTO roster (last_name, first_name, grade, instrument, email, date_of_birth, address1, address2, city, state, zip, associated_user_id, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param('sssssssssssii', $data['last_name'], $data['first_name'], $data['grade'], $data['instrument'], $data['email'], $data['date_of_birth'], $data['address1'], $data['address2'], $data['city'], $data['state'], $data['zip'], $data['associated_user_id'], $user_id);
        if ($stmt->execute()) {
            session_write_close();
            echo '<script>window.parent.location.href="category.php?id=8&t=' . time() . '"; window.parent.closeFormModal();</script>';
            exit;
        } else {
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    }
}

$_SESSION['form_rendered_' . $request_id] = true;
error_log("roster_form.php: Step 6 - Form marked as rendered");
session_write_close();
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FireSafe - Roster Form</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
            color: #dc2626;
            font-size: 0.875rem;
            margin-top: 0.25rem;
            display: none;
        }
    </style>
</head>
<body class="bg-white min-h-screen">
    <div class="form-container">
        <h2 class="text-xl font-bold mb-1"><?php echo $record_id ? 'Edit' : ($is_batch_edit ? 'Batch Edit' : 'Add'); ?> Roster Record</h2>
        <form method="POST" action="" class="bg-white rounded-lg" id="rosterForm">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Last Name</label>
                    <input type="text" name="last_name" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['last_name'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">First Name</label>
                    <input type="text" name="first_name" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['first_name'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Grade</label>
                    <input type="text" name="grade" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['grade'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Instrument</label>
                    <input type="text" name="instrument" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['instrument'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">E-mail</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['email'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Date of Birth</label>
                    <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['date_of_birth'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Address 1</label>
                    <input type="text" name="address1" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['address1'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Address 2</label>
                    <input type="text" name="address2" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['address2'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">City</label>
                    <input type="text" name="city" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['city'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">State</label>
                    <input type="text" name="state" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['state'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Zip</label>
                    <input type="text" name="zip" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['zip'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Balance (Read-Only)</label>
                    <input type="text" value="<?php echo htmlspecialchars($is_batch_edit ? 'N/A' : ($record['balance'] ?? '0.00')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-300" readonly>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Associated User</label>
                    <select name="associated_user_id" id="associated_user_id" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200" <?php echo $is_batch_edit ? 'disabled' : ''; ?>>
                        <option value="">None</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo (!$is_batch_edit && isset($record['associated_user_id']) && $record['associated_user_id'] == $user['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['name'] . ' (' . $user['role'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p id="associated_user_error" class="error-message">This user is already associated with another roster record.</p>
                </div>
            </div>
            <div class="button-group">
                <button type="button" id="cancelButton" class="cancel-button">Cancel</button>
                <button type="submit" class="save-button" id="saveButton">Save</button>
            </div>
        </form>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            console.log('roster_form.php: DOMContentLoaded event fired at', new Date().toISOString());

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
                            window.parent.location.href = "category.php?id=8&t=" + Date.now();
                            console.log('Last resort: Navigated parent to refresh page');
                        }
                    }
                } catch (error) {
                    console.error('Error in attemptCloseModal:', error.message, error.stack);
                    // Fallback in case of error: Navigate parent to refresh
                    try {
                        window.parent.location.href = "category.php?id=8&t=" + Date.now();
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

const associatedUserSelect = document.getElementById('associated_user_id');
const errorMessage = document.getElementById('associated_user_error');
const saveButton = document.getElementById('saveButton');
const recordId = <?php echo json_encode($record_id); ?>;

if (associatedUserSelect) {
    const validateUser = (userId) => {
        if (!userId) {
            console.log('No user selected, clearing validation state');
            errorMessage.style.display = 'none';
            saveButton.disabled = false;
            return;
        }

        console.log('Validating associated user ID:', userId, 'for record ID:', recordId);
        $.ajax({
            url: 'check_user_association.php',
            type: 'POST',
            data: {
                user_id: userId,
                record_id: recordId
            },
            dataType: 'json',
            timeout: 5000, // 5-second timeout
            success: function(response) {
                console.log('Validation response:', response);
                if (response.isAssociated) {
                    errorMessage.style.display = 'block';
                    saveButton.disabled = true;
                } else {
                    errorMessage.style.display = 'none';
                    saveButton.disabled = false;
                }
            },
            error: function(xhr, status, error) {
                console.error('Error checking user association:', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });
                errorMessage.style.display = 'none';
                saveButton.disabled = false;
                console.log('Proceeding with save enabled despite validation failure');
            }
        });
    };

    associatedUserSelect.addEventListener('change', function() {
        const userId = this.value;
        validateUser(userId);
    });

    const initialValue = associatedUserSelect.value;
    console.log('Initial associated_user_id value:', initialValue);
    if (initialValue && initialValue !== '') {
        // Delay the initial validation to ensure the DOM is fully loaded
        setTimeout(() => {
            console.log('Triggering initial validation for associated_user_id');
            validateUser(initialValue);
        }, 0);
    }
} else {
    console.error('associated_user_id select element not found');
}
});
    </script>
</body>
</html>
<?php
error_log("roster_form.php: Step 7 - Script completed at " . microtime(true));
?>
