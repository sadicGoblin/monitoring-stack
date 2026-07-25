# Receta: agregar un sitio nuevo al monitoreo

Hay dos niveles, independientes entre sí:

- **Nivel A — Monitoreo externo** (cualquier sitio, WordPress o no):
  uptime, latencia, código HTTP, vencimiento SSL.
- **Nivel B — Métricas internas de WordPress** (requiere instalar el plugin):
  updates pendientes, logins fallidos, WP-Cron, tamaño de BD, seguridad.

Un WordPress normalmente lleva los dos. Una página estática solo el A.

> Ruta del repo en el servidor: `/opt/monitoring-stack`

---

## Nivel A — Monitoreo externo (2 minutos)

**1.** Edita `prometheus/prometheus.yml` y agrega la URL en el job
`blackbox_http`:

```yaml
      - targets:
          - https://favric.cl
          - https://sitio-nuevo.cl        # ← nueva línea
```

**2.** Aplica (según dónde editaste):

```bash
# si editaste en tu Mac:
git add -A && git commit -m "Agrega sitio-nuevo.cl" && git push
# y en el servidor:
cd /opt/monitoring-stack && git pull && docker compose restart prometheus

# si editaste directo en el servidor: solo el restart
# (recuerda después alinear git para no perder el cambio en el próximo pull)
```

**3.** Verifica: en ~30 s el sitio aparece solo en el dashboard
**Monitoreo de Sitios**. Las alertas (caído / lento / SSL) aplican
automáticamente.

---

## Nivel B — Métricas internas de WordPress (5 minutos)

**1. Instalar el plugin** (el mismo zip sirve para todos los sitios):

- wp-admin del sitio → **Plugins → Añadir nuevo → Subir plugin**
- Subir `wordpress-plugin/favric-monitor.zip` → **Activar**
- Si no tienes el zip a mano: `cd wordpress-plugin && zip -r favric-monitor.zip favric-monitor`

**2. Copiar el token**: wp-admin → **Ajustes → Favric Monitor**.
La página muestra el endpoint, el token y un `curl` de prueba.

**3. Guardar el token en el servidor** (elige un nombre corto para el
sitio, ej. `idris`):

```bash
cd /opt/monitoring-stack
mkdir -p prometheus/secrets
echo -n 'PEGA_EL_TOKEN_AQUI' > prometheus/secrets/idris.token
```

⚠️ Usa `echo -n` (sin salto de línea final). Los tokens **nunca van a
git** (la carpeta `secrets/` está en `.gitignore`).

**4. Agregar el job de scrape** en `prometheus/prometheus.yml`
(copia este bloque cambiando las 3 líneas marcadas):

```yaml
  - job_name: wp_idris                                    # ← nombre único
    metrics_path: /wp-json/favric-monitor/v1/metrics
    scheme: https
    scrape_interval: 5m
    authorization:
      type: Bearer
      credentials_file: /etc/prometheus/secrets/idris.token   # ← mismo nombre del paso 3
    static_configs:
      - targets: ["idrispatagonia.com"]                   # ← dominio sin https://
```

**5. Aplicar y verificar:**

```bash
docker compose restart prometheus

# ¿llega la data? (debe mostrar wp_version del sitio nuevo)
curl -sG 'localhost:9090/api/v1/query' --data-urlencode 'query=wp_info'
```

El sitio aparece solo en el dashboard **Salud WordPress** y quedan
activas sus alertas (plugins desactualizados, fuerza bruta, WP-Cron).

---

## Si no llega la data (diagnóstico en orden)

```bash
# 1) ¿Prometheus ve el target y qué error da?
curl -s localhost:9090/api/v1/targets | python3 -m json.tool | grep -B2 -A8 wp_idris

# 2) ¿El endpoint responde con el token guardado?
curl -H "Authorization: Bearer $(cat prometheus/secrets/idris.token)" \
  https://idrispatagonia.com/wp-json/favric-monitor/v1/metrics | head -5
```

| Síntoma | Causa típica |
|---|---|
| `404 rest_no_route` | Token incorrecto o con salto de línea (rehacer paso 3 con `echo -n`), o plugin no activado |
| `No such file or directory` | Falta el archivo del token en `prometheus/secrets/` |
| Target no aparece en `/targets` | El job quedó comentado o no se reinició Prometheus |
| `/wp-json` da 404 en todo | El sitio no tiene enlaces permanentes "bonitos": usar `metrics_path: /` + `params: {rest_route: ["/favric-monitor/v1/metrics"]}` |

---

## Quitar un sitio

Borrar su línea (nivel A) y/o su job + archivo de token (nivel B),
luego `docker compose restart prometheus`. El histórico de métricas se
conserva 30 días y luego expira solo.
