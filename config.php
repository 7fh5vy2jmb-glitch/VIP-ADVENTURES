<?php
/*
 * CONFIGURACIÓN DE VIP ADVENTOUR
 * Edita solo este archivo. No lo compartas ni lo subas a un repositorio público.
 */

// Tipo de base de datos: 'mysql' (lo normal en un hosting) o 'sqlite' (un solo archivo, sin servidor).
const DB_TIPO = 'mysql';

// --- MySQL / MariaDB: los datos te los da tu hosting al crear la base de datos ---
const DB_HOST    = 'localhost';
const DB_NOMBRE  = 'vip_adventour';
const DB_USUARIO = 'usuario_de_la_base';
const DB_CLAVE   = 'clave_de_la_base';

// --- SQLite: ruta del archivo. Ponlo FUERA de la carpeta pública del sitio si puedes ---
const DB_SQLITE = __DIR__ . '/../vip-adventour.sqlite';

// Zona horaria para folios y fechas.
const ZONA = 'America/Mexico_City';

// Secreto para poder usar instalar.php (solo la primera vez). Cámbialo por una frase larga
// que solo tú conozcas, y después de instalar BORRA instalar.php.
const INSTALAR_TOKEN = 'cambia-esto-por-un-secreto';
