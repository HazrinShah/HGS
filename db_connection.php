<?php

$host = "localhost"; 
$user = "hgscom_hgs";     
$pass = "2YznZ9j2KReCdsbp37WX"; 
$db   = "hgscom_hgs";         

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Database connection failed");
}

$conn->set_charset("utf8mb4");

?>
