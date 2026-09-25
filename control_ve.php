<?php
/**
 * control_ve.php
 *
 * Controla la carga de Twister a través de la API de Octopus España (Kraken).
 *
 *   ?accion=suspender       -> aparta a Octopus, el wallbox manda (para excedente solar)
 *   ?accion=reanudar        -> devuelve el control a Octopus
 *   ?accion=boost           -> carga inmediata ahora, ignorando ventana (viaje mañana)
 *   ?accion=cancelar_boost  -> cancela la carga inmediata
 *   ?accion=estado          -> lee isSuspended / currentState actuales
 *
 * Loxone llama a las 4 primeras como Salida Virtual HTTP, y a "estado" como
 * Entrada Virtual HTTP para verificar que el comando surtió efecto.
 */

header('Content-Type: application/json; charset=utf-8');

$cfg = require __DIR__ . '/config.php';
$oc  = $cfg['octopus'];

$accion     = $_GET['accion'] ?? '';
$permitidas = ['suspender', 'reanudar', 'boost', 'cancelar_boost', 'estado'];

if (!in_array($accion, $permitidas, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Acción no válida. Usa: ' . implode(', ', $permitidas)]);
    exit;
}

function gql(string $endpoint, string $query, array $variables = [], ?string $token = null): array
{
    $body    = json_encode(['query' => $query, 'variables' => $variables]);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($token) {
        $headers[] = 'Authorization: ' . $token; // JWT en crudo, sin "Bearer"
    }

    // CloudFront (WAF de Octopus) devuelve 403 a peticiones sin User-Agent
    // reconocible, así que simulamos uno de navegador normal.
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        return ['error' => 'curl: ' . $err];
    }
    return json_decode($res, true) ?? ['error' => 'Respuesta no JSON', 'raw' => $res];
}

$endpoint = 'https://api.oees-kraken.energy/v1/graphql/';
$deviceId = $oc['device_id'];

// --- Token con caché en fichero: evita pedir uno nuevo en cada llamada ---
// (el JWT de Kraken dura ~1h; renovamos con margen a los 50 min)
$tokenFile = __DIR__ . '/octopus_token.json';
$token     = null;

if (is_readable($tokenFile)) {
    $cache = json_decode(file_get_contents($tokenFile), true);
    if (is_array($cache) && ($cache['exp'] ?? 0) > time()) {
        $token = $cache['token'];
    }
}

if (!$token) {
    $auth = gql(
        $endpoint,
        'mutation($input: ObtainJSONWebTokenInput!) { obtainKrakenToken(input: $input) { token } }',
        ['input' => ['email' => $oc['email'], 'password' => $oc['password']]]
    );
    $token = $auth['data']['obtainKrakenToken']['token'] ?? null;

    if (!$token) {
        http_response_code(502);
        echo json_encode(['error' => 'No se pudo obtener token de Octopus', 'detalle' => $auth]);
        exit;
    }

    file_put_contents(
        $tokenFile,
        json_encode(['token' => $token, 'exp' => time() + 3000]) // 50 min
    );
}

// --- Ejecutar la acción pedida ---
switch ($accion) {
    case 'suspender':
        $r = gql(
            $endpoint,
            'mutation($id: ID!) {
                updateDeviceSmartControl(input: { deviceId: $id, action: SUSPEND }) {
                    ... on SmartFlexVehicle { id status { isSuspended currentState } }
                }
            }',
            ['id' => $deviceId], $token
        );
        break;

    case 'reanudar':
        $r = gql(
            $endpoint,
            'mutation($id: ID!) {
                updateDeviceSmartControl(input: { deviceId: $id, action: UNSUSPEND }) {
                    ... on SmartFlexVehicle { id status { isSuspended currentState } }
                }
            }',
            ['id' => $deviceId], $token
        );
        break;

    case 'boost':
        $r = gql(
            $endpoint,
            'mutation($id: String!) {
                updateBoostCharge(input: { deviceId: $id, action: BOOST }) {
                    ... on SmartFlexVehicle { id status { isSuspended currentState } }
                }
            }',
            ['id' => $deviceId], $token
        );
        break;

    case 'cancelar_boost':
        $r = gql(
            $endpoint,
            'mutation($id: String!) {
                updateBoostCharge(input: { deviceId: $id, action: CANCEL }) {
                    ... on SmartFlexVehicle { id status { isSuspended currentState } }
                }
            }',
            ['id' => $deviceId], $token
        );
        break;

    case 'estado':
        $r = gql(
            $endpoint,
            'query($acc: String!) {
                devices(accountNumber: $acc) {
                    id
                    ... on SmartFlexVehicle { status { current isSuspended currentState } }
                }
            }',
            ['acc' => $oc['account_number']], $token
        );
        break;
}

// --- Salida simplificada para Loxone (comandos de reconocimiento cortos) ---
$isSuspended = null;
$currentState = null;

if ($accion === 'estado') {
    $isSuspended  = $r['data']['devices'][0]['status']['isSuspended']  ?? null;
    $currentState = $r['data']['devices'][0]['status']['currentState'] ?? null;
} else {
    $campo = $accion === 'boost' || $accion === 'cancelar_boost' ? 'updateBoostCharge' : 'updateDeviceSmartControl';
    $isSuspended  = $r['data'][$campo]['status']['isSuspended']  ?? null;
    $currentState = $r['data'][$campo]['status']['currentState'] ?? null;
}

$ok = !isset($r['errors']);

// Log solo de lo que importa: cualquier acción que NO sea "estado" (suspender,
// reanudar, boost, cancelar_boost), siempre; y "estado" solo si algo falló.
// Así el log queda con las transiciones reales, no con el polling de cada 30s.
if ($accion !== 'estado' || !$ok) {
    file_put_contents(
        __DIR__ . '/control_ve.log',
        date('Y-m-d H:i:s') . " accion={$accion} ok=" . ($ok ? '1' : '0')
            . " is_suspended=" . var_export($isSuspended, true)
            . " current_state=" . ($currentState ?? 'null')
            . ($ok ? '' : ' error=' . json_encode($r['errors'] ?? $r))
            . "\n",
        FILE_APPEND
    );
}

echo json_encode([
    'accion'        => $accion,
    'is_suspended'  => $isSuspended === true ? 1 : ($isSuspended === false ? 0 : null),
    'current_state' => $currentState,
    'ok'            => $ok,
    'raw'           => $r,
]);