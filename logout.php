<?php
   session_start();
   error_log("logout.php: Starting logout process");
   session_unset();
   session_destroy();
   error_log("logout.php: Session destroyed, redirecting to index.php");
   header('Location: index.php');
   exit;
   ?>
