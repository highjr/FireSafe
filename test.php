<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
echo "PHP is working at " . date('Y-m-d H:i:s') . "<br>";
$mysqli = new mysqli('mysql.jasonhigh.com', 'firesafe_admin', '!!122Romans', 'firesafe_testsql');
if ($mysqli->connect_error) {
    die("Database connection failed: " . $mysqli->connect_error);
}
echo "Database connection successful!";
?><?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
echo "PHP is working at " . date('Y-m-d H:i:s') . "<br>";
$mysqli = new mysqli('mysql.jasonhigh.com', 'firesafe_admin', '!!122Romans', 'firesafe_testsql');
if ($mysqli->connect_error) {
    die("Database connection failed: " . $mysqli->connect_error);
}
echo "Database connection successful!";
?>
