<?php
// Database connection
$servername = "localhost";
$username = "root"; 
$password = "";     
$dbname = "hgs";

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die(json_encode(["success" => false, "message" => "Database connection failed."]));
}
?>
