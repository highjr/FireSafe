<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_log("music_library_form.php: Step 1 - Script started at " . microtime(true));
$request_uri = $_SERVER['REQUEST_URI'];
$request_id = md5($request_uri . microtime(true));
$_SESSION['current_request_id'] = $request_id;
// Reset form_rendered for this request
unset($_SESSION['form_rendered_' . $request_id]);
error_log("music_library_form.php: Step 2 - Request ID set: " . $request_id);
if (isset($_SESSION['form_rendered_' . $request_id]) && $_SESSION['form_rendered_' . $request_id]) {
    error_log("music_library_form.php: Step 3 - Already rendered for request_id: " . $request_id . ", exiting");
    session_write_close();
    exit;
}

require_once 'config.php';
error_log("music_library_form.php: Step 4 - Config included at " . microtime(true));

if (!isset($_SESSION['user_id'])) {
    error_log("music_library_form.php: Step 5 - No user logged in, redirecting to index.php");
    session_write_close();
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$record_id = isset($_GET['record_id']) ? (int)$_GET['record_id'] : 0;
$record_ids = isset($_GET['record_ids']) ? array_map('intval', explode(',', $_GET['record_ids'])) : [];
$record = null;
$is_batch_edit = !empty($record_ids);

if ($record_id) {
    $stmt = $mysqli->prepare('SELECT * FROM music_library WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $record_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $record = $result->fetch_assoc();
    $stmt->close();
} elseif ($is_batch_edit) {
    $placeholders = implode(',', array_fill(0, count($record_ids), '?'));
    $stmt = $mysqli->prepare("SELECT * FROM music_library WHERE id IN ($placeholders) AND user_id = ?");
    $types = str_repeat('i', count($record_ids)) . 'i';
    $params = array_merge($record_ids, [$user_id]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    // For batch edit, we don't set $record to avoid displaying a single record's data
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['library_no', 'title', 'composer', 'arranger', 'publisher', 'year', 'genre', 'difficulty', 'ensemble_type', 'last_performed'];
    $data = [];
    foreach ($fields as $field) {
        $data[$field] = isset($_POST[$field]) ? trim($_POST[$field]) : '';
    }

    if ($record_id) {
        // Single record update
        $query = 'UPDATE music_library SET library_no = ?, title = ?, composer = ?, arranger = ?, publisher = ?, year = ?, genre = ?, difficulty = ?, ensemble_type = ?, last_performed = ? WHERE id = ? AND user_id = ?';
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param('ssssssssssii', $data['library_no'], $data['title'], $data['composer'], $data['arranger'], $data['publisher'], $data['year'], $data['genre'], $data['difficulty'], $data['ensemble_type'], $data['last_performed'], $record_id, $user_id);
        if ($stmt->execute()) {
            session_write_close();
            echo '<script>window.parent.location.href="category.php?id=4&t=' . time() . '"; window.parent.closeFormModal();</script>';
            exit;
        } else {
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    } elseif ($is_batch_edit) {
        // Batch update
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
                $query = 'UPDATE music_library SET ' . implode(', ', $set_clause) . ' WHERE id = ? AND user_id = ?';
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
        echo '<script>window.parent.location.href="category.php?id=4&t=' . time() . '"; window.parent.closeFormModal();</script>';
        exit;
    } else {
        // Insert new record
        $query = 'INSERT INTO music_library (library_no, title, composer, arranger, publisher, year, genre, difficulty, ensemble_type, last_performed, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param('ssssssssssi', $data['library_no'], $data['title'], $data['composer'], $data['arranger'], $data['publisher'], $data['year'], $data['genre'], $data['difficulty'], $data['ensemble_type'], $data['last_performed'], $user_id);
        if ($stmt->execute()) {
            session_write_close();
            echo '<script>window.parent.location.href="category.php?id=4&t=' . time() . '"; window.parent.closeFormModal();</script>';
            exit;
        } else {
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    }
}

$_SESSION['form_rendered_' . $request_id] = true;
error_log("music_library_form.php: Step 6 - Form marked as rendered");
session_write_close();
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FireSafe - Music Library Form</title>
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
    </style>
</head>
<body class="bg-white min-h-screen">
    <div class="form-container">
        <h2 class="text-xl font-bold mb-1"><?php echo $record_id ? 'Edit' : ($is_batch_edit ? 'Batch Edit' : 'Add'); ?> Music Library Record</h2>
        <form method="POST" action="" class="bg-white rounded-lg">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Library No.</label>
                    <input type="text" name="library_no" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['library_no'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Title</label>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['title'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Composer</label>
                    <input type="text" name="composer" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['composer'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Arranger</label>
                    <input type="text" name="arranger" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['arranger'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Publisher</label>
                    <input type="text" name="publisher" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['publisher'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Year</label>
                    <input type="text" name="year" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['year'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Genre</label>
                    <input type="text" name="genre" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['genre'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Difficulty</label>
                    <input type="text" name="difficulty" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['difficulty'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Ensemble Type</label>
                    <input type="text" name="ensemble_type" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['ensemble_type'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Last Performed</label>
                    <input type="text" name="last_performed" value="<?php echo htmlspecialchars($is_batch_edit ? '{No change}' : ($record['last_performed'] ?? '')); ?>" class="mt-0.5 block w-full border-gray-500 rounded-md bg-gray-200">
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
            console.log('music_library_form.php: DOMContentLoaded event fired at', new Date().toISOString());

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
                            window.parent.location.href = "category.php?id=4&t=" + Date.now();
                            console.log('Last resort: Navigated parent to refresh page');
                        }
                    }
                } catch (error) {
                    console.error('Error in attemptCloseModal:', error.message, error.stack);
                    // Fallback in case of error: Navigate parent to refresh
                    try {
                        window.parent.location.href = "category.php?id=4&t=" + Date.now();
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
                    if (e.target.tagName !== 'INPUT') {
                        e.preventDefault();
                        console.log('Enter key pressed, triggering save');
                        document.querySelector('.save-button').click();
                    }
                }
            });
            console.log('Keydown listener attached for Esc and Enter keys');
        });
    </script>
</body>
</html>
<?php
error_log("music_library_form.php: Step 7 - Script completed at " . microtime(true));
?>
