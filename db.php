<?php
/* Conexión a la base de datos y creación de tablas. Funciona con MySQL/MariaDB y con SQLite. */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $op = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if (DB_TIPO === 'sqlite') {
        $pdo = new PDO('sqlite:' . DB_SQLITE, null, null, $op);
    } else {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NOMBRE . ';charset=utf8mb4',
            DB_USUARIO,
            DB_CLAVE,
            $op + [PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}

function crear_tablas(PDO $pdo): void
{
    $fin = DB_TIPO === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
        correo     VARCHAR(120) NOT NULL PRIMARY KEY,
        nombre     VARCHAR(100) NOT NULL,
        documento  VARCHAR(30)  NOT NULL,
        telefono   VARCHAR(30)  NOT NULL DEFAULT '',
        clave_hash VARCHAR(255) NOT NULL,
        creado     VARCHAR(19)  NOT NULL
    )" . $fin);

    $pdo->exec("CREATE TABLE IF NOT EXISTS pedidos (
        folio    VARCHAR(30)  NOT NULL PRIMARY KEY,
        correo   VARCHAR(120) NOT NULL,
        nombre   VARCHAR(100) NOT NULL,
        telefono VARCHAR(30)  NOT NULL,
        items    MEDIUMTEXT   NOT NULL,
        personas INT          NOT NULL,
        total    DOUBLE       NOT NULL,
        cotizar  TINYINT      NOT NULL DEFAULT 0,
        pago     VARCHAR(40)  NOT NULL,
        notas    VARCHAR(300) NOT NULL DEFAULT '',
        estado   VARCHAR(20)  NOT NULL DEFAULT 'Pendiente',
        enviado  TINYINT      NOT NULL DEFAULT 0,
        oculto   TINYINT      NOT NULL DEFAULT 0,
        creado   VARCHAR(19)  NOT NULL
    )" . $fin);

    $pdo->exec("CREATE TABLE IF NOT EXISTS config (
        clave VARCHAR(50) NOT NULL PRIMARY KEY,
        valor MEDIUMTEXT  NOT NULL
    )" . $fin);

    $pdo->exec("CREATE TABLE IF NOT EXISTS intentos (
        ip   VARCHAR(45) NOT NULL,
        tipo VARCHAR(12) NOT NULL,
        ts   INT         NOT NULL
    )" . $fin);

    // Índice para buscar rápido los pedidos de un cliente. Si ya existe, se ignora el error.
    try {
        $pdo->exec('CREATE INDEX idx_pedidos_correo ON pedidos (correo)');
    } catch (PDOException $e) {
        // ya existía
    }
}
