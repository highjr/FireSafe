<?php
session_start();

$request_uri = $_SERVER['REQUEST_URI'];
$request_id = md5($request_uri . microtime(true));
if (isset($_SESSION['page_rendered_' . $request_id]) && $_SESSION['page_rendered_' . $request_id]) {
    exit;
}
$_SESSION['page_rendered_' . $request_id] = true;

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPCache cleared.";
} else {
    echo "OPCache not enabled.";
}
?>
