<?php
declare(strict_types=1);

/*
 * API de VIP Adventour. El sitio la llama con fetch: api.php?a=ACCION
 * Lecturas: GET.  Escrituras: POST con JSON y cabecera X-CSRF.
 */

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

date_default_timezone_set(ZONA);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const ESTADOS = ['Pendiente', 'Confirmado', 'Pagado', 'Cancelado'];
const PAGOS   = ['Efectivo al abordar', 'Transferencia bancaria', 'Tarjeta (enlace de pago)'];

/* ---------- respuestas ---------- */

function salir(array $d, int $codigo = 200)
{
    http_response_code($codigo);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function fallo(string $mensaje, int $codigo = 400)
{
    salir(['ok' => false, 'error' => $mensaje], $codigo);
}

/* ---------- sesión ---------- */

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('vipsid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* ---------- utilidades ---------- */

function ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Corta si esta IP ya hizo demasiados intentos de este tipo en la ventana de tiempo. */
function limite(string $tipo, int $max, int $segundos): void
{
    $st = db()->prepare('SELECT COUNT(*) FROM intentos WHERE ip = ? AND tipo = ? AND ts > ?');
    $st->execute([ip(), $tipo, time() - $segundos]);
    if ((int)$st->fetchColumn() >= $max) {
        fallo('Demasiados intentos. Espera unos minutos y vuelve a probar.', 429);
    }
}

function registrar(string $tipo): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO intentos (ip, tipo, ts) VALUES (?, ?, ?)')->execute([ip(), $tipo, time()]);
    if (random_int(1, 20) === 1) {
        $pdo->prepare('DELETE FROM intentos WHERE ts < ?')->execute([time() - 86400]);
    }
}

/** Lee un texto del JSON: lo recorta y verifica el largo. */
function texto(array $in, string $campo, int $max, bool $obligatorio = true, string $nombre = ''): string
{
    $v = $in[$campo] ?? '';
    if (!is_string($v)) fallo('Datos inválidos.');
    $v = trim($v);
    $nombre = $nombre ?: $campo;
    if ($obligatorio && $v === '') fallo("Falta: $nombre.");
    if (mb_strlen($v) > $max) fallo("$nombre es demasiado largo (máximo $max caracteres).");
    return $v;
}

function config_get(string $clave): ?string
{
    $st = db()->prepare('SELECT valor FROM config WHERE clave = ?');
    $st->execute([$clave]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function config_set(string $clave, string $valor): void
{
    $pdo = db();
    if (config_get($clave) === null) {
        $pdo->prepare('INSERT INTO config (clave, valor) VALUES (?, ?)')->execute([$clave, $valor]);
    } else {
        $pdo->prepare('UPDATE config SET valor = ? WHERE clave = ?')->execute([$valor, $clave]);
    }
}

function fecha_mx(string $iso): string
{
    $t = strtotime($iso);
    return $t ? date('j/n/Y, H:i:s', $t) : $iso;
}

/* ---------- servicios ---------- */

function servicios_default(): array
{
    $j = json_decode((string)file_get_contents(__DIR__ . '/servicios-default.json'), true);
    return is_array($j) ? $j : [];
}

function servicios_db(): array
{
    $v = config_get('servicios');
    $j = $v === null ? null : json_decode($v, true);
    return is_array($j) && $j ? $j : servicios_default();
}

/** Valida lo que manda el panel de administración. Las imágenes se conservan por posición. */
function limpiar_servicios($datos, array $actual): array
{
    if (!is_array($datos) || count($datos) !== count($actual)) fallo('Los servicios no tienen el formato esperado.');
    $num = function ($v): float {
        $n = is_numeric($v) ? (float)$v : 0.0;
        return round(max(0.0, min(1000000.0, $n)), 2);
    };
    $sal = [];
    foreach (array_values($datos) as $i => $s) {
        if (!is_array($s) || !isset($s['paquetes']) || !is_array($s['paquetes'])) fallo('Servicio inválido.');
        $paq = [];
        foreach ($s['paquetes'] as $k) {
            if (!is_array($k)) fallo('Paquete inválido.');
            $paq[] = [
                'nombre' => mb_substr(trim((string)($k['nombre'] ?? '')), 0, 100) ?: 'Paquete',
                'desc'   => mb_substr(trim((string)($k['desc'] ?? '')), 0, 400),
                'precio' => $num($k['precio'] ?? 0),
                'grupo'  => $num($k['grupo'] ?? 0),
            ];
        }
        if (!$paq || count($paq) > 20) fallo('Cada servicio necesita entre 1 y 20 paquetes.');
        $sal[] = [
            'nombre'   => mb_substr(trim((string)($s['nombre'] ?? '')), 0, 100) ?: 'Servicio',
            'unidad'   => ($s['unidad'] ?? '') === 'boleto' ? 'boleto' : 'persona',
            'img'      => (string)($actual[$i]['img'] ?? ''),
            'desc'     => mb_substr(trim((string)($s['desc'] ?? '')), 0, 2000),
            'paquetes' => $paq,
        ];
    }
    return $sal;
}

/* ---------- clientes y pedidos ---------- */

function cliente_publico(array $c): array
{
    return [
        'nombre'    => $c['nombre'],
        'documento' => $c['documento'],
        'correo'    => $c['correo'],
        'telefono'  => $c['telefono'],
        'creado'    => fecha_mx($c['creado']),
    ];
}

function cliente_actual(): ?array
{
    static $c = false;
    if ($c !== false) return $c;
    $c = null;
    if (!empty($_SESSION['correo'])) {
        $st = db()->prepare('SELECT * FROM clientes WHERE correo = ?');
        $st->execute([$_SESSION['correo']]);
        $f = $st->fetch();
        if ($f) $c = $f; else unset($_SESSION['correo']);
    }
    return $c;
}

function requiere_cliente(): array
{
    $c = cliente_actual();
    if (!$c) fallo('Inicia sesión para continuar.', 401);
    return $c;
}

function es_admin(): bool
{
    return !empty($_SESSION['admin']) && (time() - (int)($_SESSION['admin_ts'] ?? 0)) < 8 * 3600;
}

function requiere_admin(): void
{
    if (!es_admin()) fallo('Acceso restringido.', 401);
}

function pedido_json(array $r): array
{
    $items = json_decode((string)$r['items'], true) ?: [];
    $res = [
        'folio'     => $r['folio'],
        'creado'    => fecha_mx($r['creado']),
        'items'     => $items,
        'servicio'  => implode(' + ', array_map(fn($i) => "{$i['servicio']} ({$i['paquete']}) x{$i['personas']}", $items)),
        'paquete'   => '',
        'fechaTour' => $items[0]['fecha'] ?? '',
        'personas'  => (int)$r['personas'],
        'total'     => (float)$r['total'],
        'cotizar'   => (bool)$r['cotizar'],
        'pago'      => $r['pago'],
        'notas'     => $r['notas'],
        'estado'    => $r['estado'],
        'enviado'   => (bool)$r['enviado'],
        'cliente'   => ['nombre' => $r['nombre'], 'correo' => $r['correo'], 'telefono' => $r['telefono']],
    ];
    return $res;
}

function iniciar_sesion_cliente(string $correo): void
{
    session_regenerate_id(true);
    $_SESSION['correo'] = $correo;
}

/* ---------- entrada ---------- */

$a      = (string)($_GET['a'] ?? '');
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

const LECTURAS = ['estado', 'mis_pedidos', 'admin_datos'];
const ESCRITURAS = ['registro', 'entrar', 'salir', 'pedido', 'pedido_enviado', 'ocultar_pedidos',
    'admin_entrar', 'admin_salir', 'admin_estado', 'admin_borrar', 'admin_servicios', 'admin_restaurar'];

if (in_array($a, LECTURAS, true)) {
    if ($metodo !== 'GET') fallo('Método no permitido.', 405);
    $in = [];
} elseif (in_array($a, ESCRITURAS, true)) {
    if ($metodo !== 'POST') fallo('Método no permitido.', 405);
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) fallo('Solicitud no válida.', 415);
    if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        fallo('Tu sesión caducó. Recarga la página e inténtalo de nuevo.', 403);
    }
    $raw = (string)file_get_contents('php://input');
    if (strlen($raw) > 300000) fallo('Solicitud demasiado grande.', 413);
    $in = json_decode($raw, true);
    if (!is_array($in)) fallo('Datos inválidos.');
} else {
    fallo('Acción desconocida.', 404);
}

/* ---------- acciones ---------- */

try {
    switch ($a) {

        case 'estado':
            $c = cliente_actual();
            salir([
                'ok'        => true,
                'csrf'      => $_SESSION['csrf'],
                'cliente'   => $c ? cliente_publico($c) : null,
                'admin'     => es_admin(),
                'servicios' => servicios_db(),
            ]);

        case 'registro':
            limite('registro', 10, 3600);
            registrar('registro');
            $nombre = texto($in, 'nombre', 100, true, 'nombre');
            $doc    = texto($in, 'documento', 30, true, 'documento');
            $correo = strtolower(texto($in, 'correo', 120, true, 'correo'));
            $tel    = texto($in, 'telefono', 30, false, 'teléfono');
            $clave  = (string)($in['clave'] ?? '');
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) fallo('Escribe un correo válido.');
            if (mb_strlen($clave) < 6) fallo('La contraseña debe tener al menos 6 caracteres.');
            if (strlen($clave) > 72) fallo('La contraseña es demasiado larga (máximo 72 caracteres).');
            try {
                db()->prepare('INSERT INTO clientes (correo, nombre, documento, telefono, clave_hash, creado) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$correo, $nombre, $doc, $tel, password_hash($clave, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') fallo('Ya existe una cuenta con ese correo. Usa "Ya tengo cuenta".', 409);
                throw $e;
            }
            iniciar_sesion_cliente($correo);
            salir(['ok' => true, 'cliente' => cliente_publico(cliente_actual())]);

        case 'entrar':
            limite('login', 8, 900);
            $correo = strtolower(texto($in, 'correo', 120, true, 'correo'));
            $clave  = (string)($in['clave'] ?? '');
            $st = db()->prepare('SELECT * FROM clientes WHERE correo = ?');
            $st->execute([$correo]);
            $c = $st->fetch();
            // Si el correo no existe se verifica contra un hash falso para tardar lo mismo.
            $hash = $c ? $c['clave_hash'] : password_hash('x', PASSWORD_DEFAULT);
            if (!password_verify($clave, $hash) || !$c) {
                registrar('login');
                usleep(300000);
                fallo('Correo o contraseña incorrectos.', 401);
            }
            if (password_needs_rehash($c['clave_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE clientes SET clave_hash = ? WHERE correo = ?')
                    ->execute([password_hash($clave, PASSWORD_DEFAULT), $correo]);
            }
            iniciar_sesion_cliente($c['correo']);
            salir(['ok' => true, 'cliente' => cliente_publico($c)]);

        case 'salir':
            unset($_SESSION['correo']);
            session_regenerate_id(true);
            salir(['ok' => true]);

        case 'mis_pedidos':
            $c = requiere_cliente();
            $st = db()->prepare('SELECT * FROM pedidos WHERE correo = ? AND oculto = 0 ORDER BY creado ASC, folio ASC');
            $st->execute([$c['correo']]);
            salir(['ok' => true, 'pedidos' => array_map('pedido_json', $st->fetchAll())]);

        case 'ocultar_pedidos':
            // El cliente "borra" su historial: se le dejan de mostrar, pero la agencia conserva el registro.
            $c = requiere_cliente();
            db()->prepare('UPDATE pedidos SET oculto = 1 WHERE correo = ?')->execute([$c['correo']]);
            salir(['ok' => true]);

        case 'pedido':
            $c = requiere_cliente();
            limite('pedido', 20, 3600);
            $nombre = texto($in, 'nombre', 100, true, 'tu nombre');
            $tel    = texto($in, 'telefono', 30, true, 'tu teléfono');
            $notas  = texto($in, 'notas', 300, false, 'las notas');
            $pago   = (string)($in['pago'] ?? '');
            if (!in_array($pago, PAGOS, true)) fallo('Elige una forma de pago válida.');

            $lineas = $in['items'] ?? null;
            if (!is_array($lineas) || count($lineas) < 1 || count($lineas) > 12) {
                fallo('Agrega entre 1 y 12 boletos.');
            }
            $L      = servicios_db();
            $minimo = date('Y-m-d', strtotime('-1 day'));
            $maximo = date('Y-m-d', strtotime('+3 years'));
            $items = [];
            $total = 0.0;
            $personas = 0;
            foreach (array_values($lineas) as $n => $x) {
                $num = $n + 1;
                if (!is_array($x)) fallo("Boleto $num inválido.");
                $t = filter_var($x['tour'] ?? null, FILTER_VALIDATE_INT);
                $p = filter_var($x['paq'] ?? null, FILTER_VALIDATE_INT);
                $q = filter_var($x['personas'] ?? null, FILTER_VALIDATE_INT);
                if ($t === false || !isset($L[$t])) fallo("El tour del boleto $num no existe.");
                if ($p === false || !isset($L[$t]['paquetes'][$p])) fallo("El paquete del boleto $num no existe.");
                if ($q === false || $q < 1 || $q > 50) fallo("La cantidad del boleto $num debe ser de 1 a 50.");
                $f = (string)($x['fecha'] ?? '');
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                    fallo("Elige una fecha válida para el boleto $num.");
                }
                if ($f < $minimo || $f > $maximo) fallo("La fecha del boleto $num no es válida.");

                // El precio lo calcula el servidor con los datos guardados, no el navegador.
                $s      = $L[$t];
                $k      = $s['paquetes'][$p];
                $precio = (float)$k['precio'];
                $grupo  = (float)$k['grupo'];
                $unit   = ($q >= 5 && $grupo > 0) ? $grupo : $precio;
                $cot    = !($precio > 0);
                $sub    = $cot ? 0.0 : $q * $unit;
                $items[] = [
                    'servicio' => $s['nombre'],
                    'paquete'  => $k['nombre'],
                    'unidad'   => $s['unidad'] === 'boleto' ? 'boleto' : 'persona',
                    'personas' => $q,
                    'precio'   => $unit,
                    'total'    => $sub,
                    'cotizar'  => $cot,
                    'fecha'    => $f,
                ];
                $total    += $sub;
                $personas += $q;
            }
            $todoCotizar = count(array_filter($items, fn($i) => !$i['cotizar'])) === 0;

            $pdo = db();
            $folio = '';
            for ($intento = 0; $intento < 6; $intento++) {
                $folio = 'VIP-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
                try {
                    $pdo->prepare('INSERT INTO pedidos (folio, correo, nombre, telefono, items, personas, total, cotizar, pago, notas, estado, enviado, oculto, creado)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)')
                        ->execute([
                            $folio, $c['correo'], $nombre, $tel,
                            json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                            $personas, $total, $todoCotizar ? 1 : 0, $pago, $notas, 'Pendiente', date('Y-m-d H:i:s'),
                        ]);
                    break;
                } catch (PDOException $e) {
                    if ($e->getCode() !== '23000' || $intento === 5) throw $e;
                }
            }
            $pdo->prepare('UPDATE clientes SET nombre = ?, telefono = ? WHERE correo = ?')->execute([$nombre, $tel, $c['correo']]);
            registrar('pedido');

            $st = $pdo->prepare('SELECT * FROM pedidos WHERE folio = ?');
            $st->execute([$folio]);
            $st2 = $pdo->prepare('SELECT * FROM clientes WHERE correo = ?');
            $st2->execute([$c['correo']]);
            salir(['ok' => true, 'pedido' => pedido_json($st->fetch()), 'cliente' => cliente_publico($st2->fetch())]);

        case 'pedido_enviado':
            $c = requiere_cliente();
            $folio = texto($in, 'folio', 30, true, 'folio');
            db()->prepare('UPDATE pedidos SET enviado = 1 WHERE folio = ? AND correo = ?')->execute([$folio, $c['correo']]);
            salir(['ok' => true]);

        /* ----- administración ----- */

        case 'admin_entrar':
            limite('admin', 5, 900);
            $hash = config_get('admin_hash');
            if ($hash === null) fallo('El sitio aún no está instalado. Abre instalar.php primero.', 503);
            if (!password_verify((string)($in['clave'] ?? ''), $hash)) {
                registrar('admin');
                usleep(500000);
                fallo('Contraseña incorrecta.', 401);
            }
            session_regenerate_id(true);
            $_SESSION['admin']    = true;
            $_SESSION['admin_ts'] = time();
            salir(['ok' => true]);

        case 'admin_salir':
            unset($_SESSION['admin'], $_SESSION['admin_ts']);
            session_regenerate_id(true);
            salir(['ok' => true]);

        case 'admin_datos':
            requiere_admin();
            $pdo = db();
            $ped = $pdo->query('SELECT * FROM pedidos ORDER BY creado ASC, folio ASC')->fetchAll();
            $cli = $pdo->query('SELECT * FROM clientes ORDER BY creado ASC, correo ASC')->fetchAll();
            salir([
                'ok'        => true,
                'pedidos'   => array_map('pedido_json', $ped),
                'clientes'  => array_map('cliente_publico', $cli),
                'servicios' => servicios_db(),
            ]);

        case 'admin_estado':
            requiere_admin();
            $folio  = texto($in, 'folio', 30, true, 'folio');
            $estado = (string)($in['estado'] ?? '');
            if (!in_array($estado, ESTADOS, true)) fallo('Estado no válido.');
            $st = db()->prepare('SELECT COUNT(*) FROM pedidos WHERE folio = ?');
            $st->execute([$folio]);
            if (!(int)$st->fetchColumn()) fallo('No existe ese pedido.', 404);
            db()->prepare('UPDATE pedidos SET estado = ? WHERE folio = ?')->execute([$estado, $folio]);
            salir(['ok' => true]);

        case 'admin_borrar':
            requiere_admin();
            $folio = texto($in, 'folio', 30, true, 'folio');
            db()->prepare('DELETE FROM pedidos WHERE folio = ?')->execute([$folio]);
            salir(['ok' => true]);

        case 'admin_servicios':
            requiere_admin();
            $nuevos = limpiar_servicios($in['servicios'] ?? null, servicios_db());
            config_set('servicios', json_encode($nuevos, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
            salir(['ok' => true, 'servicios' => $nuevos]);

        case 'admin_restaurar':
            requiere_admin();
            db()->prepare('DELETE FROM config WHERE clave = ?')->execute(['servicios']);
            salir(['ok' => true, 'servicios' => servicios_default()]);
    }
} catch (PDOException $e) {
    error_log('VIP Adventour (base de datos): ' . $e->getMessage());
    fallo('No se pudo usar la base de datos. Revisa config.php o ejecuta instalar.php.', 500);
} catch (Throwable $e) {
    error_log('VIP Adventour: ' . $e->getMessage());
    fallo('Error del servidor.', 500);
}
