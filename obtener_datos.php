<?php
/**
 * obtener_datos.php
 *
 * Herramienta de una sola vez: obtén tu account_number y tu device_id de
 * Octopus para rellenar config.php. Pide el email y la contraseña por
 * formulario en el propio navegador — no hay ninguna credencial escrita en
 * este archivo, así que es seguro subirlo a un repositorio público.
 *
 * Requisito: tener Intelligent Octopus Go (u otro dispositivo Smart Flex)
 * ya vinculado desde la app de Octopus, con el coche conectado.
 *
 * Recomendación: borra este archivo del servidor cuando termines de usarlo.
 */

$endpoint = 'https://api.oees-kraken.energy/v1/graphql/';

function gql(string $endpoint, string $query, array $variables = [], ?string $token = null): array
{
    // Un array PHP vacío se serializa como [] en JSON, pero GraphQL exige que
    // "variables" sea un objeto o null cuando no hay ninguna. Sin variables,
    // se envía null explícitamente para evitar el error "variables must be
    // an object or null".
    $body = json_encode([
        'query'     => $query,
        'variables' => $variables ?: null,
    ]);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        // Sin esto, CloudFront devuelve 403 a peticiones sin User-Agent de navegador.
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    ];
    if ($token) {
        $headers[] = 'Authorization: ' . $token; // JWT en crudo, sin "Bearer"
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
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

$resultadoHtml = '';

// --- Solo se procesa si el formulario se ha enviado ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $resultadoHtml = '<p class="error">Rellena email y contraseña.</p>';
    } else {
        ob_start();

        echo "<h2>Paso 1: obteniendo token</h2>";

        $auth = gql(
            $endpoint,
            'mutation($input: ObtainJSONWebTokenInput!) { obtainKrakenToken(input: $input) { token } }',
            ['input' => ['email' => $email, 'password' => $password]]
        );

        $token = $auth['data']['obtainKrakenToken']['token'] ?? null;

        if (!$token) {
            echo '<p class="error">No se pudo obtener el token. Revisa el email/contraseña.</p>';
            echo '<pre>' . htmlspecialchars(json_encode($auth, JSON_PRETTY_PRINT)) . '</pre>';
        } else {
            echo '<p class="ok">Token obtenido correctamente.</p>';

            echo "<h2>Paso 2: tu account_number</h2>";

            $acc = gql($endpoint, 'query { viewer { accounts { number } } }', [], $token);
            $numbers = array_column($acc['data']['viewer']['accounts'] ?? [], 'number');

            if (!$numbers) {
                echo '<p class="error">No se encontró ninguna cuenta.</p>';
                echo '<pre>' . htmlspecialchars(json_encode($acc, JSON_PRETTY_PRINT)) . '</pre>';
            } else {
                echo '<ul>';
                foreach ($numbers as $n) {
                    echo '<li><code>account_number: ' . htmlspecialchars($n) . '</code></li>';
                }
                echo '</ul>';

                echo "<h2>Paso 3: tus dispositivos (device_id)</h2>";

                foreach ($numbers as $accountNumber) {
                    echo '<h3>Cuenta ' . htmlspecialchars($accountNumber) . '</h3>';

                    $dev = gql(
                        $endpoint,
                        'query($acc: String!) { devices(accountNumber: $acc) { id __typename ... on SmartFlexVehicle { name make model } } }',
                        ['acc' => $accountNumber],
                        $token
                    );

                    $devices = $dev['data']['devices'] ?? [];

                    if (!$devices) {
                        echo '<p>Ningún dispositivo Smart Flex encontrado. Asegúrate de tener ';
                        echo 'Intelligent Octopus Go vinculado y el coche conectado.</p>';
                        continue;
                    }

                    echo '<ul>';
                    foreach ($devices as $d) {
                        $nombre = $d['name'] ?? trim(($d['make'] ?? '') . ' ' . ($d['model'] ?? ''));
                        echo '<li><code>device_id: ' . htmlspecialchars($d['id']) . '</code>';
                        echo ' — ' . htmlspecialchars($d['__typename']);
                        if ($nombre) echo ' (' . htmlspecialchars($nombre) . ')';
                        echo '</li>';
                    }
                    echo '</ul>';
                }
            }
        }

        $resultadoHtml = ob_get_clean();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Obtener datos de Octopus</title>
<style>
    body { font-family: sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; }
    label { display: block; margin-top: 12px; font-weight: bold; }
    input { width: 100%; padding: 8px; box-sizing: border-box; margin-top: 4px; }
    button { margin-top: 16px; padding: 10px 20px; }
    .ok { color: #2a7; }
    .error { color: #c33; }
    code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; }
    .aviso { background: #fff8e1; padding: 12px; border-radius: 6px; margin-bottom: 20px; }
</style>
</head>
<body>

<h1>Obtener account_number y device_id de Octopus</h1>

<div class="aviso">
    Tu email y contraseña se usan solo para esta consulta y no se guardan en
    ningún sitio. Cuando termines, borra este archivo del servidor.
</div>

<form method="post">
    <label for="email">Email de Octopus</label>
    <input type="email" id="email" name="email" required>

    <label for="password">Contraseña</label>
    <input type="password" id="password" name="password" required>

    <button type="submit">Consultar</button>
</form>

<?= $resultadoHtml ?>

</body>
</html>
