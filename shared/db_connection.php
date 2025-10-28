<?php

// Toggle: set to true to use Supabase (recommended for production)
$USE_SUPABASE = true;

// Example Supabase connection string (provided earlier):
// postgresql://postgres:[YOUR-PASSWORD]@db.gcosokfwmkaxtnxpnffr.supabase.co:5432/postgres

$pdo = null;  // PDO handle (Postgres)
$conn = null; // mysqli handle (MySQL fallback)

if ($USE_SUPABASE) {
    $SUPABASE_HOST = 'db.gcosokfwmkaxtnxpnffr.supabase.co';
    $SUPABASE_PORT = 5432;
    $SUPABASE_DB   = 'postgres';
    $SUPABASE_USER = 'postgres';
    $SUPABASE_PASS = '[123456789]'; 

    $dsn = "pgsql:host={$SUPABASE_HOST};port={$SUPABASE_PORT};dbname={$SUPABASE_DB};sslmode=require";
    try {
        $pdo = new PDO($dsn, $SUPABASE_USER, $SUPABASE_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Define a minimal mysqli polyfill so existing code using $conn continues to work.
        if (!defined('MYSQLI_ASSOC')) { define('MYSQLI_ASSOC', 1); }

        class ResultPolyfill {
            private $rows;
            private $pos = 0;
            public function __construct($pdoStmt) {
                $this->rows = $pdoStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            public function fetch_all($mode = MYSQLI_ASSOC) {
                return $this->rows;
            }
            public function fetch_assoc() {
                if ($this->pos < count($this->rows)) {
                    return $this->rows[$this->pos++];
                }
                return null;
            }
        }

        class StatementPolyfill {
            private $pdo;
            private $sql;
            private $boundRefs = [];
            private $pdoStmt;
            private $parent; // MysqliPolyfill
            public $affected_rows = 0;
            public function __construct($pdo, $sql, $parent) {
                $this->pdo = $pdo;
                $this->sql = $sql;
                $this->parent = $parent;
            }
            public function bind_param($types, &...$vars) {
                // Store references to variables; types are ignored as PDO handles typing
                $this->boundRefs = &$vars;
                return true;
            }
            public function execute() {
                $this->pdoStmt = $this->pdo->prepare($this->sql);
                $values = [];
                foreach ($this->boundRefs as &$v) { $values[] = $v; }
                $ok = $this->pdoStmt->execute($values);
                // affected rows for UPDATE/INSERT/DELETE
                $this->affected_rows = $this->pdoStmt->rowCount();
                // Update parent's last insert id
                if ($this->parent) {
                    $this->parent->setLastInsertId($this->pdo->lastInsertId());
                }
                return $ok;
            }
            public function get_result() {
                return new ResultPolyfill($this->pdoStmt);
            }
            public function close() {
                $this->pdoStmt = null;
            }
        }

        class MysqliPolyfill {
            private $pdo;
            private $lastInsertId = 0;
            public function __construct($pdo) { $this->pdo = $pdo; }
            public function prepare($sql) { return new StatementPolyfill($this->pdo, $sql, $this); }
            public function close() { /* no-op */ }
            public function setLastInsertId($id) { $this->lastInsertId = $id; }
            public function __get($name) {
                if ($name === 'insert_id') { return $this->lastInsertId; }
                return null;
            }
        }

        // Expose $conn as polyfill so existing code keeps working
        $conn = new MysqliPolyfill($pdo);
    } catch (PDOException $e) {
        error_log('Supabase Postgres connection failed: ' . $e->getMessage());
        // Fallback to local MySQL to avoid hard failure during migration
        $USE_SUPABASE = false;
    }
}

if (!$USE_SUPABASE) {
    // Local MySQL (mysqli) fallback
    $servername = 'localhost';
    $username   = 'root';
    $password   = '';
    $dbname     = 'hgs';

    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die(json_encode([ 'success' => false, 'message' => 'Database connection failed.' ]));
    }
}

// Usage notes:
// - Prefer $pdo in migrated files (Postgres):
//     $stmt = $pdo->prepare('SELECT * FROM booking WHERE hikerID = :id');
//     $stmt->execute([':id' => $hikerID]);
//     $rows = $stmt->fetchAll();
// - Legacy files may still use $conn (mysqli) and will work only when $USE_SUPABASE=false.
// - SQL differences when moving to Postgres:
//     CURDATE() -> CURRENT_DATE
//     NOW() -> NOW()
//     INTERVAL 1 MINUTE -> INTERVAL '1 minute'
//     CONCAT(a,b) -> a || b (or concat(a,b))
?>
