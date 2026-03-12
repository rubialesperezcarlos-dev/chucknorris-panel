<?php
/**
 * Chuck Norris AI - Dashboard login
 */

require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['chuck_norris_ai_user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($user === '' || $pass === '') {
        $error = 'Usuario y contraseña obligatorios.';
    } else {
        $pdo = getDb();
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$user]);
        $row = $stmt->fetch();
        if ($row && password_verify($pass, $row['password_hash'])) {
            $_SESSION['chuck_norris_ai_user_id'] = (int)$row['id'];
            $_SESSION['chuck_norris_ai_username'] = $user;
            $redirect = $_GET['redirect'] ?? 'index.php';
            $redirect = (strpos($redirect, 'login.php') !== false) ? 'index.php' : $redirect;
            header('Location: ' . $redirect);
            exit;
        }
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chuck Norris AI - Login</title>
    <style>
        :root { --bg: #0d1117; --surface: #161b22; --border: #30363d; --text: #e6edf3; --accent: #58a6ff; --danger: #f85149; }
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 32px; width: 100%; max-width: 360px; }
        h1 { font-size: 1.25rem; margin: 0 0 24px; color: var(--accent); }
        label { display: block; margin-bottom: 6px; font-size: 14px; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg); color: var(--text); margin-bottom: 16px; }
        button { width: 100%; padding: 12px; background: var(--accent); color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
        button:hover { opacity: 0.9; }
        .error { background: rgba(248,81,73,0.15); color: var(--danger); padding: 10px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Chuck Norris AI – Login</h1>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" action="">
            <label for="username">Usuario</label>
            <input type="text" id="username" name="username" required autofocus>
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" required>
            <button type="submit">Entrar</button>
        </form>
    </div>
</body>
</html>
