# PHP APM Demo — Azure App Service + Datadog

Demo de instrumentación APM para una aplicación PHP corriendo en Azure App Service como **custom container**, con el Datadog Agent como sidecar.

## Arquitectura

```
Tu app PHP
  └── Tracer PHP (ddtrace extension)
        │  captura spans, logs, métricas
        │  envía a localhost:8126
        ▼
Sidecar ddagent (datadog/serverless-init)
  └── Recibe de localhost:8126
        │  envía a Datadog
        ▼
Datadog (APM → Services, Logs, Metrics)
```

El tracer captura telemetría (trazas, logs, métricas) y la envía al agente que corre como sidecar. El agente la reenvía a Datadog.

> El agente **no** se instala con `startup.sh` ni extensiones de App Service — se despliega como sidecar container.

---

## Requisitos previos

- Azure App Service con una imagen PHP custom container
- API Key de Datadog
- Azure Container Registry (ACR) para alojar la imagen
- Azure CLI instalado localmente

---

## Paso 1 — Variables de entorno en el App Service

**Azure Portal → App Service → Settings → Environment variables**

| Variable | Valor |
|---|---|
| `DD_API_KEY` | Tu API Key de Datadog |
| `DD_SITE` | `datadoghq.com` (o el site de tu cuenta) |
| `DD_SERVICE` | Nombre de tu servicio, ej. `mi-app` |
| `DD_ENV` | `prd` |
| `DD_VERSION` | Versión de tu app, ej. `1.0.0` (opcional) |
| `DD_TRACE_ENABLED` | `true` |
| `DD_LOGS_INJECTION` | `true` |
| `WEBSITES_ENABLE_APP_SERVICE_STORAGE` | `true` |

> No agregues `DD_TRACE_AGENT_URL` ni `DD_AGENT_HOST` — el tracer los detecta automáticamente cuando el sidecar corre en el mismo host.

---

## Paso 2 — Agregar el sidecar de Datadog

**Azure Portal → App Service → Deployment Center → Add → Add container**

| Campo | Valor |
|---|---|
| Name | `ddagent` |
| Image source | Docker Hub |
| Image type | Public |
| Registry server URL | `index.docker.io` |
| Image and tag | `datadog/serverless-init:latest` |
| Port | `8126` |
| Startup command | *(dejar vacío)* |

Para las variables de entorno del sidecar, activa:

> ☑ **Allow access to all app settings**

El sidecar hereda automáticamente todas las variables configuradas en el Paso 1.

---

## Paso 3 — Tracer PHP en el Dockerfile

Agrega este bloque **después** de instalar PHP y **antes** de copiar el código:

```dockerfile
RUN curl -LO https://github.com/DataDog/dd-trace-php/releases/latest/download/datadog-setup.php \
 && php datadog-setup.php --php-bin=all \
 && rm datadog-setup.php
```

### Dockerfile de referencia

```dockerfile
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    unzip \
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_mysql

# Tracer de Datadog PHP
RUN curl -LO https://github.com/DataDog/dd-trace-php/releases/latest/download/datadog-setup.php \
 && php datadog-setup.php --php-bin=all \
 && rm datadog-setup.php

RUN a2enmod rewrite

COPY . /var/www/html/

# Redirigir logs de Apache a stdout/stderr para que Datadog los capture
RUN ln -sf /proc/1/fd/1 /var/log/apache2/access.log \
 && ln -sf /proc/1/fd/2 /var/log/apache2/error.log

EXPOSE 80
```

---

## Paso 4 — Logs estructurados (recomendado)

Para que los logs aparezcan correlacionados con las trazas en Datadog, deben escribirse a stdout en formato JSON con los campos `dd.trace_id` y `dd.span_id`:

```php
$traceId = null;
$spanId  = null;

if (extension_loaded('ddtrace')) {
    $traceId = \DDTrace\logs_correlation_trace_id();
    $span    = \DDTrace\active_span();
    if ($span !== null) {
        $spanId = $span->hexId();
    }
}

$logEntry = [
    '@timestamp'  => date('c'),
    'level'       => 'INFO',
    'message'     => 'Tu mensaje aquí',
    'service'     => getenv('DD_SERVICE'),
    'env'         => getenv('DD_ENV'),
    'dd.trace_id' => $traceId,
    'dd.span_id'  => $spanId,
];

file_put_contents('php://stdout', json_encode($logEntry) . PHP_EOL);
```

---

## Paso 5 — Deploy y reinicio

```bash
# Configurar variables requeridas
export DD_API_KEY=<tu-api-key>
export ACR_NAME=<nombre-de-tu-acr>

# Build, push y deploy
./support/php-apm-demo/build-push.sh
./support/php-apm-demo/deploy.sh
```

Después del deploy, reinicia el App Service:

**Azure Portal → App Service → Overview → Restart**

Espera **2–3 minutos** a que ambos contenedores estén corriendo.

---

## Paso 6 — Validar

1. Genera tráfico real en tu aplicación (login, búsquedas, etc.).
2. Espera 3–5 minutos.
3. En Datadog ve a **APM → Services** y busca el valor de `DD_SERVICE`.

Un log correctamente instrumentado se ve así:

```json
{
  "@timestamp": "2026-06-18T03:48:23+00:00",
  "level": "INFO",
  "message": "User logged in",
  "service": "mi-app",
  "env": "prd",
  "dd.trace_id": "e373655c7d0da45477033895a0193c42",
  "dd.span_id": "24cafcb40fa7ac70"
}
```

La presencia de `dd.trace_id` confirma que el tracer PHP está activo.

---

## Troubleshooting

| Síntoma | Qué revisar |
|---|---|
| El servicio no aparece en APM | Verifica `DD_API_KEY` y `DD_SITE` |
| `DD_SITE` incorrecto | Debe coincidir con la URL donde te logueas a Datadog |
| Sidecar no activo | En Deployment Center confirma que `ddagent` aparezca corriendo |
| Variables no llegan al agente | Activa "Allow access to all app settings" en el sidecar |
| Tracer no detectado | Verifica que el `Dockerfile` tenga el bloque de `datadog-setup.php` y que hayas hecho rebuild |
| Sin trazas | Genera tráfico después del restart — sin requests no hay trazas |

### Mensajes esperados (no son errores)

| Mensaje | ¿Problema? |
|---|---|
| `Workloadmeta collectors are not ready after N retries` | No. Advertencia esperada en App Service, no afecta APM. |
| `SiteStartupCancelled` en logs de startup | No, si el contenedor terminó levantando. |

---

## Estructura del repositorio

```
support/
  php-apm-demo/       # Código de la app demo (PHP 8.3 + Apache)
  conf.yaml           # Configuración de referencia para el Datadog Agent (SQL Server check)
  example.yaml        # Ejemplo mínimo de conf.yaml con correcciones anotadas
  manual-jeynner-apm.md  # Guía de instrumentación (fuente de este README)
```
