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

// Fetch existing profile data
$stmt = $mysqli->prepare('SELECT * FROM user_profiles WHERE user_id = ?');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$profile = $result->fetch_assoc();
$stmt->close();

// Initialize form data
$form_data = $profile ?: array_fill_keys([
    'guardian1_first_name', 'guardian1_last_name', 'guardian1_street_address1', 'guardian1_street_address2',
    'guardian1_city', 'guardian1_state', 'guardian1_zip_code', 'guardian1_phone1', 'guardian1_phone2', 'guardian1_email',
    'guardian2_first_name', 'guardian2_last_name', 'guardian2_street_address1', 'guardian2_street_address2',
    'guardian2_city', 'guardian2_state', 'guardian2_zip_code', 'guardian2_phone1', 'guardian2_phone2', 'guardian2_email'
], '');

// US states for dropdown
$states = ['AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and validate input
    $form_data = array_map('trim', $_POST);
    
    // Validation functions
    $validate_name = function($name, $field) use (&$errors) {
        if (!empty($name) && !preg_match('/^[A-Za-z\s-]{1,50}$/', $name)) {
            $errors[$field] = "Invalid $field. Use letters, spaces, or hyphens (max 50 characters).";
        }
    };
    
    $validate_address = function($address, $field) use (&$errors) {
        if (!empty($address) && !preg_match('/^[A-Za-z0-9\s,.-]{1,100}$/', $address)) {
            $errors[$field] = "Invalid $field. Use letters, numbers, spaces, commas, periods, or hyphens (max 100 characters).";
        }
    };
    
    $validate_city = function($city, $field) use (&$errors) {
        if (!empty($city) && !preg_match('/^[A-Za-z\s-]{1,50}$/', $city)) {
            $errors[$field] = "Invalid $field. Use letters, spaces, or hyphens (max 50 characters).";
        }
    };
    
    $validate_state = function($state, $field) use (&$errors, $states) {
        if (!empty($state) && !in_array($state, $states)) {
            $errors[$field] = "Invalid $field. Select a valid US state.";
        }
    };
    
    $validate_zip = function($zip, $field) use (&$errors) {
        if (!empty($zip) && !preg_match('/^\d{5}(-\d{4})?$/', $zip)) {
            $errors[$field] = "Invalid $field. Use 12345 or 12345-6789 format.";
        }
    };
    
    $validate_phone = function($phone, $field) use (&$errors) {
        if (!empty($phone) && !preg_match('/^(\(\d{3}\)\s\d{3}-\d{4}|\d{3}-\d{3}-\d{4})$/', $phone)) {
            $errors[$field] = "Invalid $field. Use (123) 456-7890 or 123-456-7890 format.";
        }
    };
    
    $validate_email = function($email, $field) use (&$errors) {
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[$field] = "Invalid $field. Use a valid email address.";
        }
    };
    
    // Validate all fields
    foreach (['guardian1', 'guardian2'] as $guardian) {
        $validate_name($form_data[$guardian . '_first_name'], $guardian . '_first_name');
        $validate_name($form_data[$guardian . '_last_name'], $guardian . '_last_name');
        $validate_address($form_data[$guardian . '_street_address1'], $guardian . '_street_address1');
        $validate_address($form_data[$guardian . '_street_address2'], $guardian . '_street_address2');
        $validate_city($form_data[$guardian . '_city'], $guardian . '_city');
        $validate_state($form_data[$guardian . '_state'], $guardian . '_state');
        $validate_zip($form_data[$guardian . '_zip_code'], $guardian . '_zip_code');
        $validate_phone($form_data[$guardian . '_phone1'], $guardian . '_phone1');
        $validate_phone($form_data[$guardian . '_phone2'], $guardian . '_phone2');
        $validate_email($form_data[$guardian . '_email'], $guardian . '_email');
    }
    
    if (empty($errors)) {
        // Save to database
        $stmt = $mysqli->prepare('
            INSERT INTO user_profiles (
                user_id, guardian1_first_name, guardian1_last_name, guardian1_street_address1, guardian1_street_address2,
                guardian1_city, guardian1_state, guardian1_zip_code, guardian1_phone1, guardian1_phone2, guardian1_email,
                guardian2_first_name, guardian2_last_name, guardian2_street_address1, guardian2_street_address2,
                guardian2_city, guardian2_state, guardian2_zip_code, guardian2_phone1, guardian2_phone2, guardian2_email
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                guardian1_first_name = VALUES(guardian1_first_name),
                guardian1_last_name = VALUES(guardian1_last_name),
                guardian1_street_address1 = VALUES(guardian1_street_address1),
                guardian1_street_address2 = VALUES(guardian1_street_address2),
                guardian1_city = VALUES(guardian1_city),
                guardian1_state = VALUES(guardian1_state),
                guardian1_zip_code = VALUES(guardian1_zip_code),
                guardian1_phone1 = VALUES(guardian1_phone1),
                guardian1_phone2 = VALUES(guardian1_phone2),
                guardian1_email = VALUES(guardian1_email),
                guardian2_first_name = VALUES(guardian2_first_name),
                guardian2_last_name = VALUES(guardian2_last_name),
                guardian2_street_address1 = VALUES(guardian2_street_address1),
                guardian2_street_address2 = VALUES(guardian2_street_address2),
                guardian2_city = VALUES(guardian2_city),
                guardian2_state = VALUES(guardian2_state),
                guardian2_zip_code = VALUES(guardian2_zip_code),
                guardian2_phone1 = VALUES(guardian2_phone1),
                guardian2_phone2 = VALUES(guardian2_phone2),
                guardian2_email = VALUES(guardian2_email)
        ');
        $stmt->bind_param(
            'issssssssssssssssssss',
            $user_id,
            $form_data['guardian1_first_name'],
            $form_data['guardian1_last_name'],
            $form_data['guardian1_street_address1'],
            $form_data['guardian1_street_address2'],
            $form_data['guardian1_city'],
            $form_data['guardian1_state'],
            $form_data['guardian1_zip_code'],
            $form_data['guardian1_phone1'],
            $form_data['guardian1_phone2'],
            $form_data['guardian1_email'],
            $form_data['guardian2_first_name'],
            $form_data['guardian2_last_name'],
            $form_data['guardian2_street_address1'],
            $form_data['guardian2_street_address2'],
            $form_data['guardian2_city'],
            $form_data['guardian2_state'],
            $form_data['guardian2_zip_code'],
            $form_data['guardian2_phone1'],
            $form_data['guardian2_phone2'],
            $form_data['guardian2_email']
        );
        
        if ($stmt->execute()) {
            $success = 'Profile updated successfully!';
            // Refresh form data
            $form_data = $form_data;
        } else {
            $errors['general'] = 'Failed to update profile. Please try again.';
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
    <title>FireSafe - Edit User Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen flex">
    <?php include 'sidebar.php'; ?>
    <div class="flex-1">
        <nav class="bg-blue-600 text-white p-4">
            <div class="container mx-auto flex justify-between items-center">
                <h1 class="text-xl font-bold">FireSafe</h1>
                <div>
                    <span class="mr-4">Welcome, <?php echo htmlspecialchars($role); ?>!</span>
                    <a href="logout.php" class="text-red-300 hover:text-red-100">Log Out</a>
                </div>
            </div>
        </nav>
        <div class="container mx-auto py-8">
            <h2 class="text-3xl font-bold mb-6">Edit User Profile</h2>
            <?php if ($success): ?>
                <p class="text-green-500 mb-4"><?php echo htmlspecialchars($success); ?></p>
            <?php endif; ?>
            <?php if (isset($errors['general'])): ?>
                <p class="text-red-500 mb-4"><?php echo htmlspecialchars($errors['general']); ?></p>
            <?php endif; ?>
            <form method="POST" action="">
                <div class="mb-8">
                    <h3 class="text-2xl font-semibold mb-4">Guardian 1 Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="guardian1_first_name" class="block text-sm font-medium text-gray-700">First Name</label>
                            <input type="text" id="guardian1_first_name" name="guardian1_first_name" value="<?php echo htmlspecialchars($form_data['guardian1_first_name']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian1_first_name'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian1_first_name']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian1_last_name" class="block text-sm font-medium text-gray-700">Last Name</label>
                            <input type="text" id="guardian1_last_name" name="guardian1_last_name" value="<?php echo htmlspecialchars($form_data['guardian1_last_name']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian1_last_name'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian1_last_name']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian1_street_address1" class="block text-sm font-medium text-gray-700">Street Address 1</label>
                            <input type="text" id="guardian1_street_address1" name="guardian1_street_address1" value="<?php echo htmlspecialchars($form_data['guardian1_street_address1']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian1_street_address1'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian1_street_address1']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian1_street_address2" class="block text-sm font-medium text-gray-700">Street Address 2</label>
                            <input type="text" id="guardian1_street_address2" name="guardian1_street_address2" value="<?php echo htmlspecialchars($form_data['guardian1_street_address2']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian1_street_address2'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian1_street_address2']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian1_city" class="block text-sm font-medium text-gray-700">City</label>
                            <input type="text" id="guardian1_city" name="guardian1_city" value="<?php echo htmlspecialchars($form_data['guardian1_city']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian1_city'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian1_city']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian1_state" class="block text-sm font-medium text-gray-700">State</label>
                            <select id="guardian1_state" name="guardian1_state" class="mt-1 w-full p-2 border rounded-md">
                                <option value="">Select State</option>
                                <?php foreach ($states as $state): ?>
                                    <option value="<?php echo $state; ?>" <?php echo $form_data['guardian1_state'] === $state ? 'selected' : ''; ?>><?php echo $state; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['guardian1_state'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian1_state']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian1_zip_code" class="block text-sm font-medium text-gray-700">Zip Code</label>
                            <input type="text" id="guardian1_zip_code" name="guardian1_zip_code" value="<?php echo htmlspecialchars($form_data['guardian1_zip_code']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian1_zip_code'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian1_zip_code']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian1_phone1" class="block text-sm font-medium text-gray-700">Phone 1</label>
                            <input type="text" id="guardian1_phone1" name="guardian1_phone1" value="<?php echo htmlspecialchars($form_data['guardian1_phone1']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian1_phone1'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian1_phone1']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian1_phone2" class="block text-sm font-medium text-gray-700">Phone 2</label>
                            <input type="text" id="guardian1_phone2" name="guardian1_phone2" value="<?php echo htmlspecialchars($form_data['guardian1_phone2']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian1_phone2'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian1_phone2']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian1_email" class="block text-sm font-medium text-gray-700">Email</label>
                            <input type="email" id="guardian1_email" name="guardian1_email" value="<?php echo htmlspecialchars($form_data['guardian1_email']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian1_email'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian1_email']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="mb-8">
                    <h3 class="text-2xl font-semibold mb-4">Guardian 2 Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="guardian2_first_name" class="block text-sm font-medium text-gray-700">First Name</label>
                            <input type="text" id="guardian2_first_name" name="guardian2_first_name" value="<?php echo htmlspecialchars($form_data['guardian2_first_name']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian2_first_name'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian2_first_name']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian2_last_name" class="block text-sm font-medium text-gray-700">Last Name</label>
                            <input type="text" id="guardian2_last_name" name="guardian2_last_name" value="<?php echo htmlspecialchars($form_data['guardian2_last_name']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian2_last_name'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian2_last_name']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian2_street_address1" class="block text-sm font-medium text-gray-700">Street Address 1</label>
                            <input type="text" id="guardian2_street_address1" name="guardian2_street_address1" value="<?php echo htmlspecialchars($form_data['guardian2_street_address1']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian2_street_address1'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian2_street_address1']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian2_street_address2" class="block text-sm font-medium text-gray-700">Street Address 2</label>
                            <input type="text" id="guardian2_street_address2" name="guardian2_street_address2" value="<?php echo htmlspecialchars($form_data['guardian2_street_address2']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian2_street_address2'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian2_street_address2']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian2_city" class="block text-sm font-medium text-gray-700">City</label>
                            <input type="text" id="guardian2_city" name="guardian2_city" value="<?php echo htmlspecialchars($form_data['guardian2_city']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian2_city'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian2_city']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian2_state" class="block text-sm font-medium text-gray-700">State</label>
                            <select id="guardian2_state" name="guardian2_state" class="mt-1 w-full p-2 border rounded-md">
                                <option value="">Select State</option>
                                <?php foreach ($states as $state): ?>
                                    <option value="<?php echo $state; ?>" <?php echo $form_data['guardian2_state'] === $state ? 'selected' : ''; ?>><?php echo $state; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['guardian2_state'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian2_state']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian2_zip_code" class="block text-sm font-medium text-gray-700">Zip Code</label>
                            <input type="text" id="guardian2_zip_code" name="guardian2_zip_code" value="<?php echo htmlspecialchars($form_data['guardian2_zip_code']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian2_zip_code'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian2_zip_code']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian2_phone1" class="block text-sm font-medium text-gray-700">Phone 1</label>
                            <input type="text" id="guardian2_phone1" name="guardian2_phone1" value="<?php echo htmlspecialchars($form_data['guardian2_phone1']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian2_phone1'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian2_phone1']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian2_phone2" class="block text-sm font-medium text-gray-700">Phone 2</label>
                            <input type="text" id="guardian2_phone2" name="guardian2_phone2" value="<?php echo htmlspecialchars($form_data['guardian2_phone2']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian2_phone2'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian2_phone2']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="guardian2_email" class="block text-sm font-medium text-gray-700">Email</label>
                            <input type="email" id="guardian2_email" name="guardian2_email" value="<?php echo htmlspecialchars($form_data['guardian2_email']); ?>" class="mt-1 w-full p-2 border rounded-md">
                            <?php if (isset($errors['guardian2_email'])): ?>
                                <p class="text-red-500 text-sm mt-1"><?php echo htmlspecialchars($errors['guardian2_email']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <button type="submit" class="w-full md:w-auto bg-blue-600 text-white p-2 rounded-md hover:bg-blue-700">Save Profile</button>
                <a href="category.php?id=1" class="ml-4 inline-block text-blue-600 hover:underline">Back to Profile</a>
            </form>
        </div>
    </div>
</body>
</html>
