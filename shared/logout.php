<?php
session_start();
session_unset(); // clear session variables
session_destroy(); // destroy the session
header("Location: /HGS/index.html"); // pergi landing page balik
exit;
?>