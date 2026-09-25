# Octopus-API
API Secilla para conexión y control de Intelligent GO

Permite suspender o reanudar Intelligent Octopus Go, y forzar una carga inmediata, por API — sin depender de la app. Pensado para integrarlo con Home Assistant, Loxone, Node-RED o cualquier automatización propia.

La API GraphQL de Octopus España no está documentada públicamente para estas operaciones. Todo lo de aquí sale de introspección directa contra el esquema real.

## Requisitos

- Cuenta de octopusenergy.es con Intelligent Octopus Go activo
- El vehículo ya vinculado desde la app de Octopus (Smart Flex)
- Un servidor con PHP y cURL

## Instalación

1. Descarga o clona este repositorio en tu servidor
2. Copia `config.example.php` a `config.php`
3. Sube `obtener_datos.php`, ábrelo en el navegador y rellena tu email y contraseña de Octopus — te devuelve tu `account_number` y el `device_id` de cada vehículo vinculado
4. Copia esos dos valores, junto con tu email y contraseña, a `config.php`
5. Borra `obtener_datos.php` del servidor — ya ha cumplido su función
6. Prueba con `control_ve.php?accion=estado`

## Endpoint

```
https://api.oees-kraken.energy/v1/graphql/
```

GraphQL, no REST — todo pasa por esta única URL, cambiando la query en el cuerpo del POST.

**Aviso importante**: CloudFront devuelve 403 a peticiones sin `User-Agent` de navegador. Si haces tus propias llamadas fuera de `control_ve.php`, incluye siempre esa cabecera.

**Aviso GraphQL**: si una consulta no lleva variables, `variables` debe enviarse como `null`, no como `{}` ni `[]` — un array vacío de PHP serializado da error.

## Uso

| Acción | Qué hace |
|---|---|
| `?accion=suspender` | Octopus deja de controlar el coche (para que mande tu propia lógica) |
| `?accion=reanudar` | Octopus retoma el control normal |
| `?accion=boost` | Carga inmediata a tope, ignorando la ventana programada |
| `?accion=cancelar_boost` | Cancela la carga inmediata |
| `?accion=estado` | Devuelve `is_suspended` y `current_state` |

Respuesta de `?accion=estado`:
```json
{
  "accion": "estado",
  "is_suspended": 0,
  "current_state": "SMART_CONTROL_IN_PROGRESS",
  "ok": true
}
```

### Valores de `current_state`

| Valor | Significado |
|---|---|
| `SMART_CONTROL_NOT_AVAILABLE` | Sin visibilidad del vehículo (desconectado, o sin datos) |
| `SMART_CONTROL_OFF` | Suspendido: Octopus no está gestionando la carga |
| `SMART_CONTROL_CAPABLE` | Activo, sin plan de carga en este momento |
| `SMART_CONTROL_IN_PROGRESS` | Octopus tiene un plan y lo está gestionando |
| `BOOSTING` | Carga inmediata en curso |

## Otras mutaciones disponibles en el esquema

Confirmadas por introspección, no usadas en este script pero documentadas por si te sirven:

- `setDevicePreferences` — preferencias del dispositivo (límite de carga, hora objetivo)
- `updateDeviceGridExport` — control de exportación a red
- `updateIsChargingDurationCapped` — limita la duración de la sesión
- `deauthenticateFlexDevice` — desvincula el dispositivo
- `startReAuthentication` — fuerza reautenticación del vínculo con el fabricante

Ninguna mutación de Octopus despierta el coche ni fuerza el inicio real de la carga — ver limitación conocida más abajo.

## Limitación conocida: el coche puede no despertar

Si el vehículo entra en reposo mientras espera corriente (por ejemplo, conectado de noche sin excedente solar y sin que Octopus tenga aún el control), reanudar el control de Octopus **no garantiza** que el coche empiece a cargar. Es un comportamiento documentado de Tesla, no un fallo de esta integración: el vehículo puede ignorar la disponibilidad de corriente si está dormido, y necesita un comando explícito (`charge_start` de la Tesla Fleet API, o equivalente vía Tessie) para despertar y arrancar la sesión.

Si tu automatización depende de que la carga arranque sin intervención, complementa `reanudar` con un pulso `charge_start` hacia la API de tu fabricante en el mismo instante del cambio de modo.

## Seguridad

- `config.php` está en `.gitignore` — nunca subas tus credenciales reales
- El token de Kraken caduca en aproximadamente 1 hora; el script lo cachea en `octopus_token.json` (también ignorado por git) y lo renueva solo
- `obtener_datos.php` no guarda ni registra las credenciales que introduces en su formulario — se usan solo en memoria para esa consulta puntual

## Licencia

Ver `LICENSE`.
