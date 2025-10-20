<?php
// db_init_sqlite3_class.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$db_file = 'notes.sqlite';
$db = null;

try {
    // 1. Open or create the SQLite database file
    $db = new SQLite3($db_file);
    // Enable foreign key constraints (crucial for relational integrity in SQLite)
    $db->exec('PRAGMA foreign_keys = ON;');

    // --- Create tables ---
    $db->exec("
        CREATE TABLE IF NOT EXISTS folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            color TEXT DEFAULT '#2196f3',
            parent_id INTEGER DEFAULT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE
        );
    ");

    $db->exec("
		CREATE TABLE IF NOT EXISTS notes (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			folder_id INTEGER,
			title TEXT NOT NULL,
			content TEXT,
			media TEXT,
			created_at TEXT DEFAULT CURRENT_TIMESTAMP,
			updated_at TEXT DEFAULT CURRENT_TIMESTAMP, -- ADDED THIS LINE
			FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL
		);
    ");

    echo "✅ Database initialized successfully: {$db_file}\n";
    echo "Tables created: folders, notes\n";

} catch (Exception $e) {
    // The SQLite3 class throws a generic 'Exception' on connection/open errors
    die("❌ Database initialization failed: " . $e->getMessage());
} finally {
    // 3. Close the database connection
    if ($db) {
        $db->close();
    }
}
?>
