=== Favric Monitor ===
Contributors: favric
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Expone métricas internas de WordPress en formato Prometheus, protegidas por
token, para el stack de monitoreo Prometheus + Grafana.

== Description ==

Registra el endpoint de solo lectura:

    GET /wp-json/favric-monitor/v1/metrics

Protegido con `Authorization: Bearer <token>` (o `?token=` como fallback si el
hosting elimina la cabecera). Sin token válido responde 404.

Métricas: versiones WP/PHP, updates pendientes (core/plugins/temas), plugins
activos, usuarios, posts, comentarios spam/pendientes, WP-Cron atrasado,
tamaño de BD, logins fallidos, WP_DEBUG y editor de archivos.

== Installation ==

1. Subir el zip en Plugins → Añadir nuevo → Subir plugin, y activar.
2. Copiar el token desde Ajustes → Favric Monitor.
3. Configurar el job de scrape en Prometheus con ese token.

Nota: requiere enlaces permanentes "bonitos" activos. Si /wp-json/ responde
404, usar la ruta alternativa /?rest_route=/favric-monitor/v1/metrics
