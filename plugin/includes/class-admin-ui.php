<?php
/**
 * Bricks API Bridge — Admin "Connect" interface.
 *
 * A self-contained WordPress admin screen that turns the headless REST bridge
 * into a guided setup: status checks, one-click Application Password creation,
 * a copy-paste config generator for every common MCP client, and a connection
 * test. Pure PHP + inline CSS/JS — no build step, no external assets.
 *
 * Purely additive: this registers an admin menu only. It does not touch any
 * REST route, auth path, or existing behavior. A user who never opens the page
 * is unaffected.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bricks_API_Bridge_Admin_UI {

	const PAGE_SLUG    = 'bricks-mcp-connect';
	const APP_PW_LABEL = 'Bricks MCP';

	/**
	 * Wire up the admin hooks. Called once on plugins_loaded.
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_chip' ), 100 );
		add_filter(
			'plugin_action_links_' . plugin_basename( BRICKS_API_BRIDGE_PLUGIN_FILE ),
			array( __CLASS__, 'plugin_action_links' )
		);
	}

	/**
	 * Register the top-level "Bricks MCP" admin menu.
	 */
	public static function add_menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#a7aaad"><path d="M3 3h6v6H3V3zm8 0h6v6h-6V3zM3 11h6v6H3v-6zm8 0h6v6h-6v-6z"/></svg>'
		);
		add_menu_page(
			'Bricks MCP',
			'Bricks MCP',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			$icon,
			58
		);
	}

	/**
	 * Add a "Connect" shortcut on the Plugins list row.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function plugin_action_links( $links ) {
		$url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$links = array_merge(
			array( '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Connect', 'bricks-api-bridge' ) . '</a>' ),
			$links
		);
		return $links;
	}

	/**
	 * Handle POST actions (create / revoke Application Password). Nonce-guarded.
	 */
	public static function handle_actions() {
		if ( empty( $_POST['bab_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = sanitize_text_field( wp_unslash( $_POST['bab_action'] ) );

		if ( 'create_app_password' === $action ) {
			check_admin_referer( 'bab_create_app_password' );
			if ( ! class_exists( 'WP_Application_Passwords' ) ) {
				self::redirect_with( array( 'bab_err' => 'no_app_pw' ) );
				return;
			}
			$user_id = get_current_user_id();
			$created = WP_Application_Passwords::create_new_application_password(
				$user_id,
				array( 'name' => self::APP_PW_LABEL . ' (' . gmdate( 'Y-m-d' ) . ')' )
			);
			if ( is_wp_error( $created ) ) {
				self::redirect_with( array( 'bab_err' => 'create_failed' ) );
				return;
			}
			// $created[0] is the one-time plaintext password. Pass it via a short-lived
			// transient (never via the URL) so it survives the redirect exactly once.
			$token = wp_generate_password( 20, false );
			set_transient( 'bab_new_pw_' . $token, $created[0], 120 );
			self::redirect_with( array( 'bab_pw' => $token ) );
			return;
		}

		if ( 'revoke_app_password' === $action ) {
			check_admin_referer( 'bab_revoke_app_password' );
			$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
			if ( $uuid && class_exists( 'WP_Application_Passwords' ) ) {
				WP_Application_Passwords::delete_application_password( get_current_user_id(), $uuid );
			}
			self::redirect_with( array( 'bab_revoked' => '1' ) );
			return;
		}
	}

	/**
	 * Redirect back to the page with query args (post-redirect-get).
	 *
	 * @param array $args Query args to add.
	 */
	private static function redirect_with( $args ) {
		$url = add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Heuristic: does this domain look like a live production site?
	 *
	 * @return bool
	 */
	private static function is_production_like() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		$host = strtolower( $host );
		foreach ( array( '.local', '.test', '.dev', 'localhost', 'staging', '.ddev.site', '127.0.0.1' ) as $needle ) {
			if ( false !== strpos( $host, $needle ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Admin-bar status chip linking to the Connect page.
	 *
	 * @param WP_Admin_Bar $bar Admin bar instance.
	 */
	public static function admin_bar_chip( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ready = self::bricks_active() && self::app_passwords_available();
		$color = $ready ? '#46b450' : '#dba617';
		$label = $ready ? 'MCP ready' : 'MCP setup';
		$bar->add_node(
			array(
				'id'    => 'bricks-mcp',
				'title' => '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . $color . ';margin-right:6px;vertical-align:middle;"></span>' . esc_html( $label ),
				'href'  => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
			)
		);
	}

	private static function bricks_active() {
		return defined( 'BRICKS_VERSION' ) || class_exists( '\Bricks\Database' );
	}

	private static function app_passwords_available() {
		return class_exists( 'WP_Application_Passwords' )
			&& wp_is_application_passwords_available();
	}

	/**
	 * Existing Application Passwords created through this screen.
	 *
	 * @return array
	 */
	private static function our_app_passwords() {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return array();
		}
		$all = WP_Application_Passwords::get_user_application_passwords( get_current_user_id() );
		$out = array();
		foreach ( (array) $all as $pw ) {
			if ( isset( $pw['name'] ) && 0 === strpos( $pw['name'], self::APP_PW_LABEL ) ) {
				$out[] = $pw;
			}
		}
		return $out;
	}

	/**
	 * Render the Connect page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bricks_ok = self::bricks_active();
		$app_ok    = self::app_passwords_available();
		$rest_base = esc_url_raw( rest_url( 'bricks-bridge/v1' ) );
		$site_url  = home_url();
		$username  = wp_get_current_user()->user_login;
		$version   = defined( 'BRICKS_API_BRIDGE_VERSION' ) ? BRICKS_API_BRIDGE_VERSION : '?';

		// One-time plaintext password (just generated), pulled from transient.
		$new_pw = '';
		if ( isset( $_GET['bab_pw'] ) ) {
			$token  = sanitize_text_field( wp_unslash( $_GET['bab_pw'] ) );
			$new_pw = (string) get_transient( 'bab_new_pw_' . $token );
			delete_transient( 'bab_new_pw_' . $token );
		}

		$pw_for_config = $new_pw ? $new_pw : 'xxxx xxxx xxxx xxxx xxxx xxxx';
		?>
		<div class="wrap bab-connect">
			<h1>Bricks MCP — Connect</h1>
			<p class="bab-sub">Connect your AI assistant to this WordPress site in three steps. Plugin version <?php echo esc_html( $version ); ?>.</p>

			<?php if ( self::is_production_like() ) : ?>
				<div class="bab-banner bab-warn">
					<strong>⚠️ This looks like a production site.</strong>
					The MCP can create and overwrite Bricks pages. Make sure that's intended — prefer a staging site for experiments.
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['bab_err'] ) ) : ?>
				<div class="bab-banner bab-err">Could not create the Application Password. Check that Application Passwords are enabled for your user.</div>
			<?php endif; ?>

			<!-- Status grid -->
			<div class="bab-grid">
				<?php
				self::status_card( 'Plugin active', true, 'v' . $version );
				self::status_card( 'Bricks Builder', $bricks_ok, $bricks_ok ? ( defined( 'BRICKS_VERSION' ) ? 'v' . BRICKS_VERSION : 'detected' ) : 'not detected' );
				self::status_card( 'REST endpoints', true, 'bricks-bridge/v1' );
				self::status_card( 'Application Passwords', $app_ok, $app_ok ? 'enabled' : 'disabled' );
				?>
			</div>

			<!-- Step 1 -->
			<div class="bab-step">
				<div class="bab-step-h"><span class="bab-num">1</span> Create an Application Password</div>
				<div class="bab-step-b">
					<?php if ( $new_pw ) : ?>
						<div class="bab-banner bab-ok">
							<strong>Password created.</strong> Copy it now — it will not be shown again.
							<div class="bab-copyrow">
								<code id="bab-newpw"><?php echo esc_html( $new_pw ); ?></code>
								<button type="button" class="button" data-copy="#bab-newpw">Copy</button>
							</div>
						</div>
					<?php else : ?>
						<p>Generate a dedicated password for the MCP. It only grants REST access and can be revoked anytime.</p>
						<?php if ( $app_ok ) : ?>
							<form method="post">
								<?php wp_nonce_field( 'bab_create_app_password' ); ?>
								<input type="hidden" name="bab_action" value="create_app_password" />
								<button type="submit" class="button button-primary">Generate Application Password</button>
							</form>
						<?php else : ?>
							<div class="bab-banner bab-err">Application Passwords are disabled on this site. Enable them (Users → Profile) or via your host, then reload.</div>
						<?php endif; ?>
					<?php endif; ?>

					<?php
					$existing = self::our_app_passwords();
					if ( $existing ) :
						?>
						<details class="bab-manage">
							<summary><?php echo count( $existing ); ?> Bricks&nbsp;MCP password(s) on file</summary>
							<table class="widefat striped">
								<thead><tr><th>Name</th><th>Created</th><th>Last used</th><th></th></tr></thead>
								<tbody>
								<?php foreach ( $existing as $pw ) : ?>
									<tr>
										<td><?php echo esc_html( $pw['name'] ); ?></td>
										<td><?php echo esc_html( $pw['created'] ? gmdate( 'Y-m-d', $pw['created'] ) : '—' ); ?></td>
										<td><?php echo esc_html( ! empty( $pw['last_used'] ) ? gmdate( 'Y-m-d', $pw['last_used'] ) : 'never' ); ?></td>
										<td>
											<form method="post" onsubmit="return confirm('Revoke this password? Any client using it will lose access.');">
												<?php wp_nonce_field( 'bab_revoke_app_password' ); ?>
												<input type="hidden" name="bab_action" value="revoke_app_password" />
												<input type="hidden" name="uuid" value="<?php echo esc_attr( $pw['uuid'] ); ?>" />
												<button type="submit" class="button-link-delete">Revoke</button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</details>
					<?php endif; ?>
				</div>
			</div>

			<!-- Step 2 -->
			<div class="bab-step">
				<div class="bab-step-h"><span class="bab-num">2</span> Connect your AI client</div>
				<div class="bab-step-b">
					<p>Pick your client and paste the config. The MCP server is the open-source <code>bricks-mcp-open</code> project running on your machine — replace <code>/path/to/bricks-mcp-open</code> with where you cloned it.</p>
					<div class="bab-tabs" id="bab-tabs"></div>
					<div class="bab-copyrow">
						<textarea id="bab-config" rows="14" readonly></textarea>
					</div>
					<button type="button" class="button button-primary" data-copy="#bab-config">Copy config</button>
				</div>
			</div>

			<!-- Step 3 -->
			<div class="bab-step">
				<div class="bab-step-h"><span class="bab-num">3</span> Test the connection</div>
				<div class="bab-step-b">
					<p>After the client restarts, ask it: <em>"Use the bricks_connection_test tool."</em> Or verify the endpoint directly from a terminal:</p>
					<div class="bab-copyrow">
						<code id="bab-curl">curl -u "<?php echo esc_html( $username ); ?>:<?php echo esc_html( $pw_for_config ); ?>" "<?php echo esc_html( $rest_base ); ?>/stats"</code>
						<button type="button" class="button" data-copy="#bab-curl">Copy</button>
					</div>
					<p class="bab-hint">A JSON response with page/template counts means everything is wired up. A 401 means the username or Application Password is wrong.</p>
				</div>
			</div>

			<p class="bab-foot">Need hosted Visual QA, accessibility audits and the AI build pipeline? <a href="https://www.carstensachse.de/" target="_blank" rel="noopener">Bricks MCP Premium →</a></p>
		</div>

		<?php self::render_styles(); ?>
		<script>
		(function(){
			var SITE = <?php echo wp_json_encode( $site_url ); ?>;
			var USER = <?php echo wp_json_encode( $username ); ?>;
			var PW   = <?php echo wp_json_encode( $pw_for_config ); ?>;
			var SERVER = "bricks-builder";
			var PATH = "/path/to/bricks-mcp-open/index.js";

			var jsonCfg = function(){
				return JSON.stringify({
					mcpServers: {
						"bricks-builder": {
							command: "node",
							args: [PATH],
							env: { WORDPRESS_URL: SITE, WORDPRESS_USER: USER, WORDPRESS_APP_PASSWORD: PW }
						}
					}
				}, null, 2);
			};
			var clients = {
				"Claude Code":   function(){ return "// ~/.claude/.mcp.json (or project .mcp.json)\n" + jsonCfg(); },
				"Claude Desktop":function(){ return "// claude_desktop_config.json\n" + jsonCfg(); },
				"Cursor":        function(){ return "// .cursor/mcp.json\n" + jsonCfg(); },
				"Windsurf":      function(){ return "// ~/.codeium/windsurf/mcp_config.json\n" + jsonCfg(); },
				"Cherry Studio": function(){ return "// Settings → MCP Servers → add:\n" + jsonCfg(); },
				"Hermes":        function(){
					return "# config.yaml\nmcp_servers:\n  - name: " + SERVER +
						"\n    command: node\n    args: [\"" + PATH + "\"]\n    env:\n" +
						"      WORDPRESS_URL: \"" + SITE + "\"\n      WORDPRESS_USER: \"" + USER +
						"\"\n      WORDPRESS_APP_PASSWORD: \"" + PW + "\"";
				}
			};

			var tabs = document.getElementById('bab-tabs');
			var out  = document.getElementById('bab-config');
			var names = Object.keys(clients);
			names.forEach(function(name, i){
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'bab-tab' + (i === 0 ? ' active' : '');
				b.textContent = name;
				b.addEventListener('click', function(){
					out.value = clients[name]();
					var all = tabs.querySelectorAll('.bab-tab');
					for (var j = 0; j < all.length; j++){ all[j].classList.remove('active'); }
					b.classList.add('active');
				});
				tabs.appendChild(b);
			});
			out.value = clients[names[0]]();

			document.querySelectorAll('[data-copy]').forEach(function(btn){
				btn.addEventListener('click', function(){
					var el = document.querySelector(btn.getAttribute('data-copy'));
					if (!el) { return; }
					var text = (el.value !== undefined) ? el.value : el.textContent;
					navigator.clipboard.writeText(text).then(function(){
						var old = btn.textContent; btn.textContent = 'Copied ✓';
						setTimeout(function(){ btn.textContent = old; }, 1500);
					});
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * One status card.
	 *
	 * @param string $label Card label.
	 * @param bool   $ok    State.
	 * @param string $note  Sub-note.
	 */
	private static function status_card( $label, $ok, $note ) {
		$icon = $ok ? '✓' : '✕';
		$cls  = $ok ? 'ok' : 'bad';
		echo '<div class="bab-card bab-' . esc_attr( $cls ) . '">';
		echo '<span class="bab-dot">' . esc_html( $icon ) . '</span>';
		echo '<span class="bab-card-label">' . esc_html( $label ) . '</span>';
		echo '<span class="bab-card-note">' . esc_html( $note ) . '</span>';
		echo '</div>';
	}

	/**
	 * Inline styles for the page (no external asset file → no build step).
	 */
	private static function render_styles() {
		?>
		<style>
		.bab-connect{max-width:880px}
		.bab-connect .bab-sub{color:#646970;font-size:14px;margin-top:-4px}
		.bab-banner{padding:12px 14px;border-radius:6px;margin:14px 0;border:1px solid}
		.bab-banner.bab-warn{background:#fcf9e8;border-color:#dba617}
		.bab-banner.bab-err{background:#fcf0f1;border-color:#d63638}
		.bab-banner.bab-ok{background:#edfaef;border-color:#46b450}
		.bab-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:18px 0}
		.bab-card{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px}
		.bab-card .bab-dot{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;font-weight:700;flex:0 0 auto}
		.bab-card.bab-ok .bab-dot{background:#46b450}
		.bab-card.bab-bad .bab-dot{background:#dba617}
		.bab-card-label{font-weight:600}
		.bab-card-note{margin-left:auto;color:#646970;font-size:12px}
		.bab-step{background:#fff;border:1px solid #dcdcde;border-radius:10px;margin:16px 0;overflow:hidden}
		.bab-step-h{display:flex;align-items:center;gap:10px;font-size:15px;font-weight:600;padding:14px 18px;border-bottom:1px solid #f0f0f1;background:#fbfbfc}
		.bab-num{display:inline-flex;width:24px;height:24px;border-radius:50%;background:#2271b1;color:#fff;align-items:center;justify-content:center;font-size:13px}
		.bab-step-b{padding:16px 18px}
		.bab-copyrow{display:flex;align-items:center;gap:10px;margin:10px 0}
		.bab-copyrow code{display:block;flex:1;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;font-size:13px;word-break:break-all}
		.bab-connect textarea{width:100%;font-family:Menlo,Consolas,monospace;font-size:12.5px;background:#1e1e1e;color:#e6e6e6;border-radius:8px;border:none;padding:14px}
		.bab-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}
		.bab-tab{background:#f0f0f1;border:1px solid #dcdcde;border-radius:999px;padding:5px 14px;cursor:pointer;font-size:13px}
		.bab-tab.active{background:#2271b1;color:#fff;border-color:#2271b1}
		.bab-hint{color:#646970;font-size:13px}
		.bab-manage{margin-top:16px}
		.bab-manage summary{cursor:pointer;color:#2271b1}
		.bab-foot{margin-top:26px;color:#646970}
		</style>
		<?php
	}
}
