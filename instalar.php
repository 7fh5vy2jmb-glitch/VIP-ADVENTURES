<?php
declare(strict_types=1);

/*
 * INSTALADOR (se usa una sola vez).
 * 1) Llena config.php (base de datos y INSTALAR_TOKEN).
 * 2) Abre este archivo en el navegador, elige la contraseña del panel de administración.
 * 3) Cuando diga "Listo", BORRA instalar.php del servidor.
 */

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function pagina(string $titulo, string $cuerpo): void
{
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>Instalar | VIP Adventour</title>'
        . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:520px;margin:40px auto;padding:0 18px;color:#12303a}'
        . 'label{display:block;margin:14px 0 4px;font-weight:600}input{width:100%;padding:10px;font:inherit;border:1px solid #9bb;border-radius:8px;box-sizing:border-box}'
        . 'button{margin-top:18px;padding:11px 20px;font:inherit;font-weight:700;border:0;border-radius:8px;background:#0e7c86;color:#fff;cursor:pointer}'
        . '.ok{background:#e6f7ee;border:1px solid #2a9d5c;padding:14px;border-radius:8px}.err{background:#fdecec;border:1px solid #d33;padding:14px;border-radius:8px}</style></head><body>'
        . '<h1>' . htmlspecialchars($titulo) . '</h1>' . $cuerpo . '</body></html>';
    exit;
}

if (INSTALAR_TOKEN === '' || INSTALAR_TOKEN === 'cambia-esto-por-un-secreto') {
    pagina('Falta un paso', '<p class="err">Abre <strong>config.php</strong> y cambia <code>INSTALAR_TOKEN</code> por un secreto que solo tú conozcas. Después recarga esta página.</p>');
}

try {
    $pdo = db();
} catch (Throwable $e) {
    error_log('instalar.php: ' . $e->getMessage());
    pagina('No hay conexión', '<p class="err">No se pudo conectar a la base de datos. Revisa los datos de <strong>config.php</strong> y vuelve a intentar.</p>');
}

$instalado = false;
try {
    $st = $pdo->query("SELECT COUNT(*) FROM config WHERE clave = 'admin_hash'");
    $instalado = (int)$st->fetchColumn() > 0;
} catch (Throwable $e) {
    $instalado = false; // las tablas aún no existen
}

if ($instalado) {
    pagina('Ya está instalado', '<p class="ok">El sitio ya tiene su base de datos y su contraseña de administración. <strong>Borra instalar.php del servidor.</strong></p>');
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['token'] ?? '');
    $clave = (string)($_POST['clave'] ?? '');
    $otra  = (string)($_POST['clave2'] ?? '');
    if (!hash_equals(INSTALAR_TOKEN, $token)) {
        usleep(500000);
        $error = 'El secreto de instalación no coincide con el de config.php.';
    } elseif (strlen($clave) < 8) {
        $error = 'La contraseña del panel debe tener al menos 8 caracteres.';
    } elseif (strlen($clave) > 72) {
        $error = 'La contraseña es demasiado larga (máximo 72 caracteres).';
    } elseif ($clave !== $otra) {
        $error = 'Las dos contraseñas no son iguales.';
    } else {
        try {
            crear_tablas($pdo);
            $pdo->prepare('INSERT INTO config (clave, valor) VALUES (?, ?)')
                ->execute(['admin_hash', password_hash($clave, PASSWORD_DEFAULT)]);
            pagina('Listo', '<p class="ok">Base de datos creada y contraseña de administración guardada.</p>'
                . '<p><strong>Ahora borra instalar.php del servidor.</strong></p>'
                . '<p><a href="acceso.html">Ir al panel de administración</a></p>');
        } catch (Throwable $e) {
            error_log('instalar.php: ' . $e->getMessage());
            $error = 'No se pudieron crear las tablas. Revisa que el usuario de la base de datos tenga permiso de crear tablas.';
        }
    }
}

pagina('Instalar VIP Adventour',
    ($error ? '<p class="err">' . htmlspecialchars($error) . '</p>' : '')
    . '<p>Crea las tablas y define la contraseña del panel de administración.</p>'
    . '<form method="post" autocomplete="off">'
    . '<label for="token">Secreto de instalación (el INSTALAR_TOKEN de config.php)</label><input id="token" name="token" type="password" required>'
    . '<label for="clave">Contraseña del panel (mínimo 8 caracteres)</label><input id="clave" name="clave" type="password" required minlength="8" maxlength="72">'
    . '<label for="clave2">Repite la contraseña</label><input id="clave2" name="clave2" type="password" required minlength="8" maxlength="72">'
    . '<button type="submit">Instalar</button></form>');
