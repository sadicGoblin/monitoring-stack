<?php
/**
 * Plugin Name: Favric Monitor
 * Plugin URI:  https://github.com/sadicGoblin/monitoring-stack
 * Description: Expone métricas internas de WordPress en formato Prometheus, protegidas por token, para el stack de monitoreo (Prometheus + Grafana).
 * Version:     1.0.0
 * Author:      Favric
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

final class Favric_Monitor {

	const VERSION      = '1.0.0';
	const OPT_TOKEN    = 'favric_monitor_token';
	const OPT_FAILURES = 'favric_monitor_login_failures';
	const REST_NS      = 'favric-monitor/v1';

	public static function boot() {
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'wp_login_failed', array( __CLASS__, 'count_login_failure' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
	}

	/* ── Activación: genera el token una sola vez ──────────────────── */

	public static function activate() {
		if ( ! get_option( self::OPT_TOKEN ) ) {
			update_option( self::OPT_TOKEN, wp_generate_password( 48, false, false ), false );
		}
		if ( false === get_option( self::OPT_FAILURES ) ) {
			update_option( self::OPT_FAILURES, 0, false );
		}
	}

	public static function count_login_failure() {
		update_option( self::OPT_FAILURES, (int) get_option( self::OPT_FAILURES, 0 ) + 1, false );
	}

	/* ── Endpoint REST ─────────────────────────────────────────────── */

	public static function register_route() {
		register_rest_route(
			self::REST_NS,
			'/metrics',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'serve_metrics' ),
				'permission_callback' => '__return_true', // el token se valida en el callback
			)
		);
	}

	private static function token_ok( WP_REST_Request $req ) {
		$stored = (string) get_option( self::OPT_TOKEN );
		if ( '' === $stored ) {
			return false;
		}
		$auth = (string) $req->get_header( 'authorization' );
		if ( 0 === stripos( $auth, 'Bearer ' ) && hash_equals( $stored, trim( substr( $auth, 7 ) ) ) ) {
			return true;
		}
		// Fallback para hostings que eliminan la cabecera Authorization.
		$param = (string) $req->get_param( 'token' );
		return '' !== $param && hash_equals( $stored, $param );
	}

	public static function serve_metrics( WP_REST_Request $req ) {
		if ( ! self::token_ok( $req ) ) {
			// 404 idéntico al de una ruta inexistente, para no delatar el endpoint.
			return new WP_Error(
				'rest_no_route',
				'No route was found matching the URL and request method.',
				array( 'status' => 404 )
			);
		}
		nocache_headers();
		header( 'Content-Type: text/plain; version=0.0.4; charset=utf-8' );
		echo self::build_metrics(); // phpcs:ignore WordPress.Security.EscapeOutput -- formato Prometheus, texto plano.
		exit;
	}

	/* ── Métricas ──────────────────────────────────────────────────── */

	private static function build_metrics() {
		global $wpdb;

		$m   = array();
		$out = function ( $name, $help, $type, $value, $labels = array() ) use ( &$m ) {
			$l = '';
			if ( $labels ) {
				$pairs = array();
				foreach ( $labels as $k => $v ) {
					$pairs[] = $k . '="' . addcslashes( (string) $v, "\"\\\n" ) . '"';
				}
				$l = '{' . implode( ',', $pairs ) . '}';
			}
			$m[] = "# HELP {$name} {$help}";
			$m[] = "# TYPE {$name} {$type}";
			$m[] = "{$name}{$l} {$value}";
		};

		// Identidad y versiones.
		$out(
			'wp_info',
			'Versiones de WordPress y PHP.',
			'gauge',
			1,
			array(
				'wp_version'  => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
				'site'        => home_url(),
			)
		);

		// Updates pendientes (lee los transients que WP-Cron refresca dos veces al día).
		$core_pending = 0;
		$core         = get_site_transient( 'update_core' );
		if ( $core && ! empty( $core->updates ) ) {
			foreach ( $core->updates as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response ) {
					$core_pending = 1;
					break;
				}
			}
		}
		$plugins_t = get_site_transient( 'update_plugins' );
		$themes_t  = get_site_transient( 'update_themes' );
		$out( 'wp_core_update_pending', 'Actualizacion de WordPress core pendiente (0/1).', 'gauge', $core_pending );
		$out( 'wp_plugin_updates_pending', 'Plugins con actualizacion pendiente.', 'gauge', empty( $plugins_t->response ) ? 0 : count( $plugins_t->response ) );
		$out( 'wp_theme_updates_pending', 'Temas con actualizacion pendiente.', 'gauge', empty( $themes_t->response ) ? 0 : count( $themes_t->response ) );

		// Plugins instalados / activos.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out( 'wp_plugins_total', 'Plugins instalados.', 'gauge', count( get_plugins() ) );
		$out( 'wp_plugins_active', 'Plugins activos.', 'gauge', count( (array) get_option( 'active_plugins', array() ) ) );

		// Usuarios y contenido.
		$users = count_users();
		$out( 'wp_users_total', 'Usuarios registrados.', 'gauge', $users['total_users'] );
		$posts = wp_count_posts();
		$out( 'wp_posts_published', 'Entradas publicadas.', 'gauge', isset( $posts->publish ) ? (int) $posts->publish : 0 );
		$comments = wp_count_comments();
		$out( 'wp_comments_pending', 'Comentarios pendientes de moderacion.', 'gauge', (int) $comments->moderated );
		$out( 'wp_comments_spam', 'Comentarios marcados como spam.', 'gauge', (int) $comments->spam );

		// WP-Cron atrasado (eventos con mas de 10 minutos de retraso).
		$overdue = 0;
		foreach ( (array) get_option( 'cron', array() ) as $ts => $hooks ) {
			if ( is_numeric( $ts ) && $ts < time() - 600 && is_array( $hooks ) ) {
				$overdue += count( $hooks );
			}
		}
		$out( 'wp_cron_overdue_events', 'Eventos de WP-Cron atrasados mas de 10 minutos.', 'gauge', $overdue );

		// Tamano de la base de datos.
		$db_size = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s',
				DB_NAME
			)
		);
		$out( 'wp_database_size_bytes', 'Tamano de la base de datos en bytes.', 'gauge', $db_size );

		// Seguridad.
		$out( 'wp_login_failures_total', 'Intentos de login fallidos desde la activacion del plugin.', 'counter', (int) get_option( self::OPT_FAILURES, 0 ) );
		$out( 'wp_debug_enabled', 'WP_DEBUG activo (0/1); no deberia estarlo en produccion.', 'gauge', ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 1 : 0 );
		$out( 'wp_file_edit_allowed', 'Editor de archivos del admin habilitado (0/1); conviene deshabilitarlo.', 'gauge', ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ? 0 : 1 );

		// Meta del propio exporter.
		$out( 'wp_monitor_plugin_info', 'Version del plugin Favric Monitor.', 'gauge', 1, array( 'version' => self::VERSION ) );

		return implode( "\n", $m ) . "\n";
	}

	/* ── Página de administración ──────────────────────────────────── */

	public static function admin_menu() {
		add_options_page( 'Favric Monitor', 'Favric Monitor', 'manage_options', 'favric-monitor', array( __CLASS__, 'render_admin' ) );
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['favric_monitor_regenerate'] ) && check_admin_referer( 'favric_monitor_regenerate' ) ) {
			update_option( self::OPT_TOKEN, wp_generate_password( 48, false, false ), false );
			echo '<div class="notice notice-success"><p>Token regenerado. Recuerda actualizarlo en Prometheus.</p></div>';
		}
		$token    = get_option( self::OPT_TOKEN );
		$endpoint = rest_url( self::REST_NS . '/metrics' );
		?>
		<div class="wrap">
			<h1>Favric Monitor</h1>
			<p>Expone métricas internas del sitio en formato Prometheus. Sin el token correcto, el endpoint responde <code>404</code> como si no existiera.</p>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Endpoint</th><td><code><?php echo esc_html( $endpoint ); ?></code></td></tr>
				<tr><th scope="row">Token</th><td><code><?php echo esc_html( $token ); ?></code></td></tr>
			</table>
			<h2>Prueba rápida</h2>
			<p><code>curl -H "Authorization: Bearer <?php echo esc_html( $token ); ?>" "<?php echo esc_html( $endpoint ); ?>"</code></p>
			<form method="post">
				<?php wp_nonce_field( 'favric_monitor_regenerate' ); ?>
				<p>
					<button type="submit" class="button" name="favric_monitor_regenerate" value="1"
						onclick="return confirm('¿Regenerar el token? Prometheus dejará de scrapear hasta que lo actualices.');">
						Regenerar token
					</button>
				</p>
			</form>
		</div>
		<?php
	}
}

Favric_Monitor::boot();
