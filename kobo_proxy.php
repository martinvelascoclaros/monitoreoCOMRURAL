<?php
/**
 * kobo_proxy.php
 *
 * Trae los datos del proyecto COMRURAL directo de la API de KoboToolbox,
 * del lado del servidor, y se los sirve al dashboard.
 * El token NUNCA sale de este archivo: el navegador solo ve el JSON final.
 *
 * INSTALACIÓN (Hostinger):
 *   1. Sube este archivo y index.html a la MISMA carpeta de tu hosting.
 *   2. Abre index.html en el navegador. Listo.
 *
 * PARA PROBAR EL PROXY SOLO:
 *   Abre https://tudominio.com/ruta/kobo_proxy.php?debug=1
 *   Debe responder algo como {"count":123,"sample_keys":[...]}
 *
 * IMPORTANTE:
 *  - No subas este archivo a un repositorio público (contiene tu token de API).
 *  - Si sospechas que el token se filtró, revócalo y genera uno nuevo en
 *    Kobo: Configuración de cuenta -> API.
 */

// Kobo puede tardar si hay muchos registros; subimos el límite de ejecución.
@set_time_limit(180);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ---- Configuración ----
$KOBO_TOKEN  = 'db51c51b12e094abbdd682ee05343b816a0a4dbe';
$ASSET_UID   = 'aahoX72dWDWaVkr52H5ZNo';
// Cambia esto si tu cuenta de Kobo vive en otro servidor
// (revisa la URL cuando entras a tu proyecto en el navegador):
$KOBO_SERVER = 'kf.kobotoolbox.org';
// ------------------------

function fail($msg, $extra = []) {
    http_response_code(502);
    echo json_encode(array_merge(['error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('curl_init')) {
    fail('Este hosting no tiene la extensión cURL de PHP habilitada. Actívala en el panel de Hostinger (PHP Extensions) o pídeselo a soporte.');
}

function kobo_get($url, $token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Token {$token}", "Accept: application/json"],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING => '', // acepta gzip: descarga mucho más rápido
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ComruralDashboardProxy/1.0)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        fail("No se pudo conectar con Kobo: {$err}");
    }
    if ($code === 401 || $code === 403) {
        fail("Kobo rechazó el token (HTTP {$code}). Verifica el token de API en kobo_proxy.php.");
    }
    if ($code === 404) {
        fail("Kobo no encontró el proyecto (HTTP 404). Verifica el UID del proyecto y el servidor de Kobo en kobo_proxy.php.");
    }
    if ($code >= 400) {
        fail("Error al consultar la API de Kobo (HTTP {$code}) {$err}");
    }
    $decoded = json_decode($body, true);
    if ($decoded === null) {
        fail('Kobo no devolvió JSON válido', ['raw_start' => substr($body, 0, 300)]);
    }
    return $decoded;
}

$url = "https://{$KOBO_SERVER}/api/v2/assets/{$ASSET_UID}/data.json?limit=1000";
$allResults = [];
$guard = 0;

while ($url && $guard < 100) {
    $data = kobo_get($url, $KOBO_TOKEN);
    if (!isset($data['results']) || !is_array($data['results'])) {
        fail('Respuesta inesperada de Kobo (sin "results")', ['raw' => $data]);
    }
    $allResults = array_merge($allResults, $data['results']);
    $url = isset($data['next']) ? $data['next'] : null;
    $guard++;
}

// Modo diagnóstico: abre kobo_proxy.php?debug=1 en el navegador para verificar
// que el proxy funciona, sin descargar todo el JSON.
if (isset($_GET['debug'])) {
    $first = count($allResults) ? $allResults[0] : [];
    echo json_encode([
        'ok'          => true,
        'count'       => count($allResults),
        'paginas'     => $guard,
        'php_version' => PHP_VERSION,
        'sample_keys' => array_slice(array_keys($first), 0, 60),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

echo json_encode(['count' => count($allResults), 'results' => $allResults], JSON_UNESCAPED_UNICODE);
