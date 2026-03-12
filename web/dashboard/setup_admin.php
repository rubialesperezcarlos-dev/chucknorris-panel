<?php
/**
 * Chuck Norris AI - One-time setup: create users table and admin user (admin / dbkiller)
 * Run once: open in browser or php setup_admin.php. Then delete or restrict access.
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: text/html; charset=utf-8');

$pdo = getDb();

// Create table if not exists
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username)
) ENGINE=InnoDB
");

$stmt = $pdo->query("SELECT 1 FROM users WHERE username = 'admin' LIMIT 1");
if ($stmt->fetch()) {
    echo '<p>Usuario admin ya existe. <a href="index.php">Ir al panel</a> | <a href="login.php">Login</a></p>';
    exit;
}

$hash = password_hash('dbkiller', PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')->execute(['admin', $hash]);
echo '<p>Usuario <strong>admin</strong> creado con contraseña <strong>dbkiller</strong>. <a href="login.php">Ir a login</a></p>';
