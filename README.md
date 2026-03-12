# Chuck Norris AI - Panel Web

Panel de administracion y API para el ecosistema Chuck Norris AI.

## Estructura

```
web/
  api/           # API REST (PHP)
    index.php    # Router principal
    tasks.php    # Gestion de tareas (crear, poll, completar, logs)
    workers.php  # Registro y heartbeat de workers
    auth.php     # Autenticacion por API key
    db.php       # Conexion MySQL/PDO
  dashboard/     # Panel web (HTML/JS/PHP)
    index.php    # Dashboard principal (tareas, workers, logs en vivo)
    proxy.php    # Proxy para llamadas API desde el frontend
    login.php    # Login del panel
    logout.php   # Logout
  generate_report.php  # Generador de reportes HTML
  .htaccess      # Rewrite rules para Apache
```

## Instalacion

1. Subir la carpeta `web/` al servidor web con Apache + PHP + MySQL
2. Configurar la base de datos en `web/api/db.php`
3. Crear las tablas (workers, api_tasks, task_logs, users, api_keys)
4. Configurar `.htaccess` para que las rutas `/api/*` funcionen

## Requisitos

- PHP 8.0+
- MySQL 8.0+ o MariaDB
- Apache con mod_rewrite
- Extensiones PHP: pdo_mysql, json, curl
