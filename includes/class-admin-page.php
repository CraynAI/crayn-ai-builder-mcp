<?php
/**
 * Admin settings page for Crayn Safe MCP.
 *
 * @package CraynSafeMcp
 */

namespace CraynSafeMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Settings -> Crayn Safe MCP: category toggles, read-only mode, recent changes
 * with revert buttons, backups table and the MCP endpoint + client snippet.
 */
class AdminPage {

	/**
	 * Hook suffix of the options page.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'handle_revert' ) );
		add_action( 'admin_init', array( self::class, 'settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CRAYN_SAFE_MCP_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Settings shortcut in the plugin list.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=crayn-safe-mcp' ) ),
				esc_html__( 'Settings', 'crayn-safe-mcp' )
			)
		);
		return $links;
	}

	/**
	 * Add the options page.
	 *
	 * @return void
	 */
	public static function menu() {
		self::$hook = add_options_page(
			__( 'Crayn Safe MCP', 'crayn-safe-mcp' ),
			__( 'Crayn Safe MCP', 'crayn-safe-mcp' ),
			'manage_options',
			'crayn-safe-mcp',
			array( self::class, 'render' )
		);
	}

	/**
	 * Register settings (categories + read-only).
	 *
	 * @return void
	 */
	public static function settings() {
		register_setting(
			'crayn_safe_mcp',
			Settings::OPTION_NAME,
			array(
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Handle a revert POST from the recent changes table.
	 *
	 * @return void
	 */
	public static function handle_revert() {
		if ( ! isset( $_POST['crayn_safe_revert'], $_POST['crayn_safe_revert_id'] ) ) {
			return;
		}
		if ( ! check_admin_referer( 'crayn_safe_revert' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$change_id = isset( $_POST['crayn_safe_revert_id'] ) ? absint( $_POST['crayn_safe_revert_id'] ) : 0;
		$result    = ChangeAbilities::revert( $change_id );

		$redirect = remove_query_arg( array( 'crayn_reverted', 'crayn_revert_error' ), wp_get_referer() );
		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'crayn_revert_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg( 'crayn_reverted', $change_id, $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Crayn Safe MCP', 'crayn-safe-mcp' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Every write is previewable (dry run), logged with a before/after snapshot and revertible. Plugin updates take a backup first and roll back automatically if the site breaks.', 'crayn-safe-mcp' ); ?>
			</p>

			<?php
			if ( isset( $_GET['crayn_reverted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by the admin revert link; informs, does not decide.
				printf(
					'<div class="notice notice-success"><p>%s</p></div>',
					esc_html( sprintf( /* translators: %d: change id. */ __( 'Change %d was reverted successfully.', 'crayn-safe-mcp' ), absint( isset( $_GET['crayn_reverted'] ) ? wp_unslash( $_GET['crayn_reverted'] ) : '' ) ) ) ) ;
			}
			if ( isset( $_GET['crayn_revert_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by the admin revert link; informs, does not decide.
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html( sanitize_text_field( wp_unslash( $_GET['crayn_revert_error'] ) ) )
				);
			}

			if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'The MCP Adapter is not active. The bundled copy loads together with this plugin, but check that the vendor/ directory exists (composer install).', 'crayn-safe-mcp' )
				);
			}
			?>

			<form method="post" action="options.php">
				<?php settings_fields( 'crayn_safe_mcp' ); ?>
				<h2><?php esc_html_e( 'Enabled categories', 'crayn-safe-mcp' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php foreach ( Settings::categories() as $slug => $label ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[enabled_categories][<?php echo esc_attr( $slug ); ?>]" value="1"
										<?php checked( Settings::category_enabled( $slug ) ); ?> />
									<?php esc_html_e( 'Expose these abilities (and their MCP tools)', 'crayn-safe-mcp' ); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Read-only mode', 'crayn-safe-mcp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[read_only]" value="1"
									<?php checked( Settings::read_only() ); ?> />
								<?php esc_html_e( 'When enabled, every write ability returns an error (reads and dry runs still work).', 'crayn-safe-mcp' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rank Math / Yoast abilities', 'crayn-safe-mcp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[external_abilities]" value="1"
									<?php checked( Settings::external_abilities() ); ?> />
								<?php esc_html_e( 'Also list the abilities of Rank Math and Yoast SEO as tools (up to about 40 more, which makes the tool list much larger). Read-only ones are listed directly; writing ones only through safe wrappers (dry run, option snapshot, change log, revert). Off by default; the built-in SEO and Rank Math tools work either way.', 'crayn-safe-mcp' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'MCP endpoint', 'crayn-safe-mcp' ); ?></h2>
			<?php
			$endpoint = rest_url( 'crayn-safe/v1/mcp' );
			$user     = wp_get_current_user();
			?>
			<p>
				<input type="text" readonly class="large-text code" onclick="this.select()" value="<?php echo esc_attr( $endpoint ); ?>" />
			</p>
			<p class="description">
				<?php esc_html_e( 'Create a WordPress Application Password (Users > Profile) and use Basic authentication with your username and the generated password. Or point clients at the default discovery server: ', 'crayn-safe-mcp' ); ?>
				<code><?php echo esc_html( rest_url( 'mcp/mcp-adapter-default-server' ) ); ?></code>
			</p>

			<h3><?php esc_html_e( 'Claude Desktop / Claude Code / Cursor config snippet', 'crayn-safe-mcp' ); ?></h3>
			<?php
			$auth = base64_encode( $user->user_login . ':' . str_repeat( 'X', 12 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Example placeholder credentials only.
			$snippet = json_encode(
				array(
					'mcpServers' => array(
						'crayn-safe' => array(
							'url'     => $endpoint,
							'headers' => array(
								'Authorization' => 'Basic ' . $auth,
							),
						),
					),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			);
			?>
			<textarea readonly rows="10" class="large-text code" onclick="this.select()"
				aria-label="<?php esc_attr_e( 'Client configuration snippet', 'crayn-safe-mcp' ); ?>"><?php echo esc_textarea( $snippet ); ?></textarea>
			<p class="description">
			<?php
			printf(
				/* translators: 1: current username, 2: base64 marker. */
				esc_html__( 'Replace the Authorization value with a real token: it is the base64 encoding of "username:application-password". Your username is %1$s. The snippet above already contains the correct endpoint; the credentials are a placeholder ending in %2$s.', 'crayn-safe-mcp' ),
				'<code>' . esc_html( $user->user_login ) . '</code>',
				'<code>XXXXXXXXXXXX</code>'
			);
			?>
			</p>

			<hr />
			<h2><?php esc_html_e( 'Recent changes', 'crayn-safe-mcp' ); ?></h2>
			<?php if ( Settings::read_only() ) : ?>
				<p class="description"><?php esc_html_e( 'Read-only mode is ON: writes will fail until it is disabled.', 'crayn-safe-mcp' ); ?></p>
			<?php endif; ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Change', 'crayn-safe-mcp' ); ?></th>
						<th><?php esc_html_e( 'Date (UTC)', 'crayn-safe-mcp' ); ?></th>
						<th><?php esc_html_e( 'User', 'crayn-safe-mcp' ); ?></th>
						<th><?php esc_html_e( 'Object', 'crayn-safe-mcp' ); ?></th>
						<th><?php esc_html_e( 'Note', 'crayn-safe-mcp' ); ?></th>
						<th><?php esc_html_e( 'Action', 'crayn-safe-mcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$rows = ChangeLogger::list_changes( array( 'limit' => 25 ) );
					if ( empty( $rows ) ) :
						?>
						<tr><td colspan="6"><?php esc_html_e( 'No changes logged yet.', 'crayn-safe-mcp' ); ?></td></tr>
						<?php
					endif;

					foreach ( $rows as $row ) :
						?>
						<tr>
							<td><code><?php echo esc_html( $row['ability'] ); ?></code></td>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td><?php echo esc_html( (string) get_the_author_meta( 'user_login', (int) $row['user_id'] ) ); ?></td>
							<td><?php echo esc_html( $row['object_type'] . ( 0 !== (int) $row['object_id'] ? ' #' . (int) $row['object_id'] : '' ) ); ?></td>
							<td><?php echo esc_html( $row['note'] ); ?></td>
							<td>
								<?php if ( empty( $row['reverted_at'] ) ) : ?>
									<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Restore this change? The change log keeps only one snapshot, reverting twice is blocked.', 'crayn-safe-mcp' ) ); ?>');">
										<?php wp_nonce_field( 'crayn_safe_revert' ); ?>
										<input type="hidden" name="crayn_safe_revert" value="1" />
										<input type="hidden" name="crayn_safe_revert_id" value="<?php echo esc_attr( (int) $row['id'] ); ?>" />
										<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Revert', 'crayn-safe-mcp' ); ?>" />
									</form>
								<?php else : ?>
									<span title="<?php echo esc_attr( $row['reverted_at'] ); ?>"><?php esc_html_e( 'Reverted', 'crayn-safe-mcp' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<hr />
			<h2><?php esc_html_e( 'Plugin backups', 'crayn-safe-mcp' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'File', 'crayn-safe-mcp' ); ?></th>
						<th><?php esc_html_e( 'Plugin', 'crayn-safe-mcp' ); ?></th>
						<th><?php esc_html_e( 'Version', 'crayn-safe-mcp' ); ?></th>
						<th><?php esc_html_e( 'Size', 'crayn-safe-mcp' ); ?></th>
						<th><?php esc_html_e( 'Created at', 'crayn-safe-mcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$backups = PluginBackup::list_backups();
					if ( empty( $backups ) ) :
						?>
						<tr><td colspan="5"><?php esc_html_e( 'No backups yet. Each plugin update creates one automatically.', 'crayn-safe-mcp' ); ?></td></tr>
						<?php
					endif;
					foreach ( $backups as $backup ) :
						?>
						<tr>
							<td><code><?php echo esc_html( $backup['file'] ); ?></code></td>
							<td><?php echo esc_html( $backup['plugin'] ); ?></td>
							<td><?php echo esc_html( $backup['version'] ); ?></td>
							<td><?php echo esc_html( $backup['size_human'] ); ?></td>
							<td><?php echo esc_html( $backup['created_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'Backups live in wp-content/crayn-safe-backups/plugins/, protected with .htaccess deny rules. Old backups can be used via the plugin-rollback tool.', 'crayn-safe-mcp' ); ?>
			</p>
		</div>
		<?php
	}
}
