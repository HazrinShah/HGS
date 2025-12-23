<?php
session_start();
session_unset(); // clear session variables
session_destroy(); // destroy the session
header("Location: ../index.php"); // pergi landing page balik
exit;
?>