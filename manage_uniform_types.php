<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$errors = [];
$success = '';

// Handle add custom type
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_type'])) {
    $new_type = trim($_POST['new_type']);
    if (empty($new_type)) {
        $errors['new_type'] = 'Uniform item type name is required.';
    } elseif (!preg_match('/^[A-Za-z0-9\s-]{1,50}$/', $new_type)) {
        $errors['new_type'] = 'Invalid name. Use letters, numbers, spaces, or hyphens (max 50 characters).';
    } else {
        $stmt = $mysqli->prepare('INSERT INTO uniform_types (name, category) VALUES (?, "Custom")');
        $stmt->bind_param('s', $new_type);
        if ($stmt->execute()) {
            $success = 'Custom uniform item type added successfully!';
        } else {
            $errors['general'] = 'Failed to add type. It may already exist.';
        }
        $stmt->close();
    }
}

// Handle delete custom type
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_type'])) {
    $type_id = (int)$_POST['type_id'];
    $stmt = $mysqli->prepare('DELETE FROM uniform_types WHERE id = ? AND category = "Custom"');
    $stmt->bind_param('i', $type_id);
    if ($stmt->execute()) {
        $success = 'Custom uniform item type deleted successfully!';
    } else {
        $errors['general'] = 'Failed to delete type.';
    }
    $stmt->close();
}

// Fetch custom types
$stmt = $mysqli->prepare('SELECT id, name FROM uniform_types WHERE category = "Custom" ORDER BY name');
$stmt->execute();
$result = $stmt->get_result();
$custom_types = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FireSafe - Manage Uniform Types</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-white min-h-screen p-4">
    <div class="container mx-auto">
        <h2 class="text-3xl font-bold mb-6">Manage Custom Uniform Types</h2>
        <?php if ($success): ?>
            <p class="text-green-500 mb-4"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>
        <?php if (isset($errors['general'])): ?>
            <p class="text-red-500 mb-4"><?php echo htmlspecialchars($errors['general']); ?></p>
        <?php endif; ?>
        <div class="mb-8">
            <h3 class="text-2xl font-semibold mb-4">Add Custom Type</h3>
            <form method="POST" action="" class="flex flex-col md:flex-row gap-4">
                <div>
                    <input type="text" name="new_type" class="w-full md:w-64 p-2 border rounded-md" placeholder="Enter new type">
                    <?php if (isset($errors['new_type'])): ?>
                        <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['new_type']); ?></p>
                    <?php endif; ?>
                </div>
                <button type="submit" name="add_type" class="bg-blue-600 text-white p-2 rounded-md hover:bg-blue-700">Add Type</button>
            </form>
        </div>
        <div>
            <h3 class="text-2xl font-semibold mb-4">Custom Types</h3>
            <?php if ($custom_types): ?>
                <div class="overflow-x-auto">
                    <table class="w-1/2 bg-white border">
                        <thead>
                            <tr>
                                <th class="w-3/4 py-2 px-4 border">Name</th>
                                <th class="w-1/4 py-2 px-4 border text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($custom_types as $type): ?>
                                <tr>
                                    <td class="py-2 px-4 border"><?php echo htmlspecialchars($type['name']); ?></td>
                                    <td class="py-2 px-4 border text-center">
                                        <form method="POST" action="" class="inline">
                                            <input type="hidden" name="type_id" value="<?php echo $type['id']; ?>">
                                            <button type="submit" name="delete_type" class="text-red-600 hover:underline" onclick="return confirm('Are you sure you want to delete this type?')">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-gray-600">No custom uniform types added yet.</p>
            <?php endif; ?>
            <button onclick="returnToForm()" class="mt-4 inline-block text-blue-600 hover:underline focus:outline-none">Back to Add Uniform Record</button>
        </div>
    </div>
    <script>
        function returnToForm() {
            window.parent.document.querySelector('iframe').src = 'uniform_inventory_form.php';
        }
    </script>
</body>
</html>
