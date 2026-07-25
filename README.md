# Stack de Monitoreo — Prometheus + Grafana + Blackbox

Monitoreo de sitios web (WordPress o cualquier página) mediante chequeos externos:
uptime, latencia, código HTTP y vencimiento de certificados SSL.

## Arquitectura

```
Sitios web ◄── Blackbox Exporter ◄── Prometheus ──► Grafana (http://servidor:3000)
                (chequeos HTTP)       (almacena +      (dashboards + alertas)
                                       evalúa alertas)
```

## Puertos usados

| Servicio   | Puerto             | Exposición                          |
|------------|--------------------|-------------------------------------|
| Grafana    | `3000`             | Pública (UI web)                    |
| Prometheus | `127.0.0.1:9090`   | Solo loopback del servidor          |
| Blackbox   | `127.0.0.1:9115`   | Solo loopback del servidor          |

## Despliegue

```bash
git clone <este-repo> && cd <este-repo>
cp .env.example .env        # edita GRAFANA_ADMIN_PASSWORD
docker compose up -d
```

Luego abre `http://<ip-del-servidor>:3000` — usuario y clave del `.env`.
El dashboard **Monitoreo → Monitoreo de Sitios** ya viene provisionado.

## Agregar sitios a monitorear

Edita [prometheus/prometheus.yml](prometheus/prometheus.yml):

- Job `blackbox_http` → URLs públicas (sitios WordPress, landing pages, etc.)
- Job `blackbox_local` → servicios del propio servidor vía `host.docker.internal:<puerto>`

Después recarga la configuración sin reiniciar:

```bash
docker compose exec prometheus kill -HUP 1
# o simplemente: docker compose restart prometheus
```

## Alertas

Las reglas están en [prometheus/alerts.yml](prometheus/alerts.yml):

- **SitioCaido** — no responde por más de 2 min (crítica)
- **SitioLento** — latencia > 3 s por 5 min (warning)
- **CertificadoSSLPorVencer** — SSL vence en menos de 15 días (warning)

Se ven en la UI de Prometheus (`ssh -L 9090:localhost:9090 <servidor>` →
`http://localhost:9090/alerts`). Para recibirlas por Slack/email/Telegram, el
siguiente paso es configurar *contact points* en Grafana Alerting o agregar
Alertmanager al compose.

## Ver la UI de Prometheus desde tu máquina

Prometheus no está expuesto a internet. Usa un túnel SSH:

```bash
ssh -L 9090:localhost:9090 root@<servidor>
```

## Próximos pasos (Fase 2)

- Plugin de WordPress que exponga `/wp-json/tu-monitor/v1/metrics` con métricas
  internas (updates pendientes, WP-Cron, BD). El job de scrape ya está esbozado
  (comentado) al final de `prometheus.yml`.
- Notificaciones de alertas (Grafana contact points o Alertmanager).
