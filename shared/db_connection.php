<?php

// cuba localhost dulu
$local = [
    'host' => 'localhost',
    'user' => 'root',
    'pass' => '',
    'db'   => 'hgs',
];

// kalau tak boleh, Hostinger remote database
$remote = [
    'host' => 'localhost',
    'user' => 'u235368206_hazrinshah04',
    'pass' => 'Shah0319_',
    'db'   => 'u235368206_hgs',
];

// elak mysqli throw exception kalau first attempt fail
if (function_exists('mysqli_report')) { @mysqli_report(MYSQLI_REPORT_OFF); }

// detect kalau running local
$hostHeader = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = ($hostHeader === 'localhost' || $hostHeader === '127.0.0.1');

// pilih order berdasarkan environment
if ($isLocal) {
    // try local dulu
    $conn = @new mysqli($local['host'], $local['user'], $local['pass'], $local['db']);
    if ($conn->connect_errno) {
        $conn = @new mysqli($remote['host'], $remote['user'], $remote['pass'], $remote['db']);
    }
} else {
    // try remote dulu
    $conn = @new mysqli($remote['host'], $remote['user'], $remote['pass'], $remote['db']);
    if ($conn->connect_errno) {
        $conn = @new mysqli($local['host'], $local['user'], $local['pass'], $local['db']);
    }
}

if ($conn->connect_errno) {
    die(json_encode([
        'success' => false,
        'message' => 'Database connection failed.'
    ]));
}

@$conn->set_charset('utf8mb4');

?>
