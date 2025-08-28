<?php
   ini_set('display_errors', 1);
   error_reporting(E_ALL);
   error_log("index.php: Starting script");
   require_once 'config.php';
   error_log("index.php: Config loaded successfully");

   $error = '';
   $current_path = $_SERVER['PHP_SELF'];
   $is_login_page = preg_match('/\/index\.php$/', $current_path);

   ob_start(); // Start output buffering to capture any errors

   // Debug: Log session status
   error_log("index.php: Checking session status");
   if (isset($_SESSION['user_id'])) {
       error_log("index.php: User is logged in, ID: " . $_SESSION['user_id']);
   } else {
       error_log("index.php: No user logged in");
   }
   error_log("index.php: Session check completed");

   if ($_SERVER['REQUEST_METHOD'] == 'POST') {
       error_log("index.php: Entering POST handling");
       $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
       $password = $_POST['password'] ?? '';

       if (empty($email) || empty($password)) {
           $error = 'Email and password are required.';
           error_log("index.php: Missing email or password");
       } else {
           try {
               $stmt = $mysqli->prepare('SELECT id, password, role FROM users WHERE email = ?');
               error_log("index.php: Prepared statement successfully");
               if ($stmt === false) {
                   throw new Exception("Prepare failed: " . $mysqli->error);
               }
               $stmt->bind_param('s', $email);
               if (!$stmt->execute()) {
                   throw new Exception("Execute failed: " . $stmt->error);
               }
               error_log("index.php: Executed statement successfully");
               $stmt->store_result();

               if ($stmt->num_rows > 0) {
                   $stmt->bind_result($id, $hashed_password, $role);
                   $stmt->fetch();
                   if (password_verify($password, $hashed_password)) {
                       $_SESSION['user_id'] = $id;
                       $_SESSION['role'] = $role;
                       error_log("index.php: Login successful, redirecting to home.php");
                       header('Location: home.php');
                       ob_end_flush();
                       exit;
                   } else {
                       $error = 'Invalid password.';
                       error_log("index.php: Invalid password for email: $email");
                   }
               } else {
                   $error = 'No user found with that email.';
                   error_log("index.php: No user found for email: $email");
               }
               $stmt->close();
               error_log("index.php: Statement closed");
           } catch (Exception $e) {
               $error = "Database error: " . $e->getMessage();
               error_log("index.php: Database error: " . $e->getMessage());
           }
       }
       error_log("index.php: Exited POST handling");
   } else {
       error_log("index.php: Not a POST request");
   }

   error_log("index.php: Determining sidebar visibility, is_login_page: " . ($is_login_page ? 'true' : 'false'));
   ?>

   <!DOCTYPE html>
   <html lang="en">
   <head>
       <meta charset="UTF-8">
       <meta name="viewport" content="width=device-width, initial-scale=1.0">
       <title>FireSafe Login</title>
       <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
   </head>
   <body class="bg-gray-100 min-h-screen <?php echo !$is_login_page && isset($_SESSION['user_id']) ? 'flex' : ''; ?>">
       <?php if (!$is_login_page && isset($_SESSION['user_id']) && file_exists('sidebar.php')): ?>
           <?php include 'sidebar.php'; error_log("index.php: Sidebar included successfully"); ?>
       <?php endif; ?>
       <div class="flex-1 flex items-center justify-center">
           <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
               <h2 class="text-2xl font-bold mb-6 text-center">FireSafe Login</h2>
               <?php if ($error): ?>
                   <p class="text-red-500 mb-4"><?php echo htmlspecialchars($error); ?></p>
               <?php endif; ?>
               <form method="POST" action="">
                   <div class="mb-4">
                       <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                       <input type="email" id="email" name="email" class="mt-1 w-full p-2 border rounded-md" required>
                   </div>
                   <div class="mb-4">
                       <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                       <input type="password" id="password" name="password" class="mt-1 w-full p-2 border rounded-md" required>
                   </div>
                   <button type="submit" class="w-full bg-blue-600 text-white p-2 rounded-md hover:bg-blue-700">Log In</button>
               </form>
               <p class="mt-4 text-center"><a href="register.php" class="text-blue-600 hover:underline">Register</a></p>
           </div>
       </div>
   </body>
   </html>
   <?php
   ob_end_flush(); // Flush output buffer to display any errors
   error_log("index.php: Script completed");
   ?>
