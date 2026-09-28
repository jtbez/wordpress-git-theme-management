<?php
/**
 * Tools → Git Deploy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GDW_Admin {

	const PAGE = 'git-deploy';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		foreach ( [ 'save', 'delete', 'deploy', 'test', 'keygen', 'settings', 'inspect', 'use_repo', 'publish' ] as $action ) {
			add_action( 'admin_post_gdw_' . $action, [ __CLASS__, 'handle_' . $action ] );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( GDW_FILE ), [ __CLASS__, 'action_links' ] );
	}

	public static function menu() {
		add_management_page( 'Git Deploy', 'Git Deploy', 'manage_options', self::PAGE, [ __CLASS__, 'render' ] );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Settings</a>' );
		return $links;
	}

	private static function url( array $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => self::PAGE ], $args ), admin_url( 'tools.php' ) );
	}

	/* ================================================================ */
	/* Rendering                                                        */
	/* ================================================================ */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$id   = isset( $_GET['repo'] ) ? GDW_Config::sanitize_id( wp_unslash( $_GET['repo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="wrap">';
		self::notice();
		if ( 'edit' === $view ) {
			self::render_edit( $id );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list() {
		$repos = GDW_Config::repos();

		echo '<h1 class="wp-heading-inline">Git Deploy</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( self::url( [ 'view' => 'edit' ] ) ) . '">Add repository</a>';
		echo '<hr class="wp-header-end">';

		if ( ! $repos ) {
			echo '<p>No repositories yet. Add a theme or plugin folder that should track a GitHub branch.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Folder</th><th>Branch</th><th>Mode</th><th>Last deploy</th><th></th></tr></thead><tbody>';
			foreach ( $repos as $r ) {
				$log = GDW_Deployer::log( $r['id'] );
				echo '<tr><td><strong><a href="' . esc_url( self::url( [ 'view' => 'edit', 'repo' => $r['id'] ] ) ) . '">' . esc_html( $r['id'] ) . '</a></strong>';
				if ( ! $r['enabled'] ) {
					echo ' <em>(disabled)</em>';
				}
				if ( $r['locked'] ) {
					echo ' <span class="dashicons dashicons-lock" title="Defined in wp-config.php"></span>';
				}
				echo '</td><td><code>wp-content/' . esc_html( $r['path'] ) . '</code></td>';
				echo '<td>' . esc_html( $r['branch'] ) . '</td>';
				echo '<td>' . esc_html( $r['mode'] ) . ( GDW_Deployer::pending( $r['id'] ) ? ' <em>(pending)</em>' : '' ) . '</td>';
				echo '<td>' . self::entry_summary( $log[0] ?? null ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				self::button( 'deploy', $r['id'], 'Deploy now' );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		self::render_checks();
		self::render_settings();
		self::render_help();
	}

	private static function render_edit( $id ) {
		$repo = $id ? GDW_Config::get( $id ) : null;

		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; All repositories</a></p>';

		if ( $id && ! $repo ) {
			echo '<h1>Git Deploy</h1><p>Repository not found.</p>';
			return;
		}

		$new = ! $repo;
		$r   = $repo ?: GDW_Config::normalize( '', [] );
		$dis = $r['locked'] ? ' disabled' : '';

		echo '<h1>' . ( $new ? 'Add repository' : 'Repository: ' . esc_html( $r['id'] ) ) . '</h1>';

		if ( $r['locked'] ) {
			echo '<div class="notice notice-info inline"><p>This repository is defined in <code>wp-config.php</code> (<code>GDW_REPOS</code>) and can only be changed there.</p></div>';
		}

		// ---- Settings form -------------------------------------------------
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="gdw_save">';
		echo '<input type="hidden" name="original_id" value="' . esc_attr( $r['id'] ) . '">';
		wp_nonce_field( 'gdw_save' );
		echo '<table class="form-table" role="presentation">';

		self::row(
			'Folder',
			self::target_field( $r, $dis ),
			'The folder name does not need to match the GitHub repository name. Nothing in the folder changes until you choose an action under Setup.'
		);

		self::row(
			'ID',
			'<input name="repo[id]" class="regular-text" value="' . esc_attr( $r['id'] ) . '" pattern="[a-z0-9_\-]*"' . ( $new ? ' placeholder="from the folder name"' : ' readonly' ) . $dis . '>',
			$new ? 'Optional. Used in the webhook URL; defaults to the folder name.' : 'Used in the webhook URL.'
		);

		self::row(
			'Repository URL',
			'<input name="repo[repo_url]" class="large-text code" value="' . esc_attr( $r['repo_url'] ) . '" placeholder="git@github.com:your-org/your-theme.git"' . $dis . '>',
			'Use the SSH URL for private repositories (authenticates with the deploy key). HTTPS works for public ones. Leave empty to use the folder\'s existing <code>origin</code>, if it has one.'
		);

		self::row( 'Branch', '<input name="repo[branch]" class="regular-text" value="' . esc_attr( $r['branch'] ) . '" required' . $dis . '>' );

		$modes = [
			'direct' => 'Direct: the webhook runs git immediately as the PHP user',
			'queue'  => 'Queue: the webhook flags it and a WP-CLI cron job runs git',
		];
		$opts  = '';
		foreach ( $modes as $val => $label ) {
			$opts .= '<option value="' . esc_attr( $val ) . '"' . selected( $r['mode'], $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		self::row( 'Mode', '<select name="repo[mode]"' . $dis . '>' . $opts . '</select>', 'Queue needs: <code>* * * * * wp --path=' . esc_html( rtrim( ABSPATH, '/' ) ) . ' git-deploy run-pending --quiet</code>' );

		self::row(
			'SSH key path',
			'<input name="repo[ssh_key]" class="large-text code" value="' . esc_attr( $r['ssh_key'] ) . '" placeholder="' . esc_attr( GDW_Config::key_dir() . '/' . ( $r['id'] ?: '<id>' ) ) . '"' . $dis . '>',
			'Optional. Leave empty to use a key generated on this page.'
		);

		$gen_js = "(function(i){var a=new Uint8Array(24);crypto.getRandomValues(a);i.value=Array.from(a,function(b){return b.toString(16).padStart(2,'0')}).join('');})(document.getElementById('gdw-secret'))";
		self::row(
			'Webhook secret',
			'<input id="gdw-secret" name="repo[secret]" class="regular-text code" value="' . esc_attr( $r['secret'] ) . '" autocomplete="off"' . $dis . '> '
				. ( $r['locked'] ? '' : '<button type="button" class="button" onclick="' . esc_attr( $gen_js ) . '">Generate</button>' ),
			'Shared with GitHub to sign requests. One is generated automatically if left empty.'
		);

		self::row(
			'Options',
			'<label><input type="checkbox" name="repo[enabled]" value="1"' . checked( $r['enabled'], true, false ) . $dis . '> Enabled (accept webhooks)</label><br>'
				. '<label><input type="checkbox" name="repo[run_hook]" value="1"' . checked( $r['run_hook'], true, false ) . $dis . '> Run <code>.deploy/deploy.sh</code> from the repository after each deploy</label><br>'
				. '<label><input type="checkbox" name="repo[protect_changes]" value="1"' . checked( $r['protect_changes'], true, false ) . $dis . '> Protect local changes: skip automatic deploys while the folder has uncommitted edits (instead of discarding them)</label>'
		);

		echo '</table>';
		if ( ! $r['locked'] ) {
			submit_button( $new ? 'Add repository' : 'Save changes' );
		}
		echo '</form>';

		if ( $new ) {
			return;
		}

		self::render_setup( $r );

		// ---- GitHub connection --------------------------------------------
		echo '<hr><h2>Connect GitHub</h2><table class="form-table" role="presentation">';

		self::row(
			'Webhook URL',
			'<input readonly class="large-text code" onclick="this.select()" value="' . esc_attr( GDW_Config::webhook_url( $r['id'] ) ) . '">',
			'GitHub webhook: content type <code>application/json</code>, this secret, "Just the push event". Or call it from GitHub Actions (see <code>examples/deploy.yml</code> in the plugin folder). Use one or the other, not both.'
		);

		echo '<tr><th scope="row">Deploy key</th><td>';
		$pub = GDW_Deployer::public_key( $r );
		if ( $pub ) {
			echo '<textarea readonly class="large-text code" rows="2" onclick="this.select()">' . esc_textarea( $pub ) . '</textarea>';
			echo '<p class="description">Add this in GitHub under the repository\'s Settings → Deploy keys. Tick "Allow write access" only if you will publish from this server; you can untick it again afterwards.</p>';
		} elseif ( '' !== $r['ssh_key'] ) {
			echo '<p>Using <code>' . esc_html( $r['ssh_key'] ) . '</code>' . ( is_readable( $r['ssh_key'] ) ? '' : ' <strong>(not readable by ' . esc_html( GDW_Deployer::whoami() ) . ')</strong>' ) . '.</p>';
		} else {
			echo '<p>No deploy key yet. Not needed for public repositories over HTTPS.</p>';
			self::button( 'keygen', $r['id'], 'Generate deploy key' );
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">Connection</th><td>';
		self::button( 'test', $r['id'], 'Test connection' );
		echo '<p class="description">Checks that this server can see the branch on GitHub.</p></td></tr>';
		echo '</table>';

		// ---- Deploy --------------------------------------------------------
		echo '<hr><h2>Deploy</h2>';
		echo '<p><strong>Working copy:</strong> <code>' . esc_html( GDW_Deployer::status( $r ) ) . '</code></p>';
		$pending = GDW_Deployer::pending( $r['id'] );
		if ( $pending ) {
			echo '<p><em>Queued ' . esc_html( human_time_diff( $pending['time'] ) ) . ' ago, waiting for the WP-CLI cron.</em></p>';
		}
		echo '<p>';
		self::button( 'deploy', $r['id'], 'Deploy now', 'button button-primary', 'Make this folder match ' . $r['branch'] . ' on GitHub? Uncommitted changes in the folder will be discarded.' );
		if ( ! $r['locked'] ) {
			echo ' ';
			self::button( 'delete', $r['id'], 'Remove', 'button button-link-delete', 'Remove this repository from Git Deploy? Files on disk are not touched.' );
		}
		echo '</p>';

		// ---- Log -------------------------------------------------------------
		echo '<h2>Recent deploys</h2>';
		$log = GDW_Deployer::log( $r['id'] );
		if ( ! $log ) {
			echo '<p>None yet.</p>';
		}
		foreach ( $log as $e ) {
			echo '<details style="margin-bottom:6px"><summary>' . self::entry_summary( $e ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;max-height:400px;overflow:auto">' . esc_html( $e['output'] ) . '</pre></details>';
		}
	}

	private static function render_checks() {
		echo '<h2>Server checks</h2><table class="widefat striped" style="max-width:1000px"><tbody>';
		foreach ( GDW_Deployer::checks() as $c ) {
			[ $label, $ok, $info ] = $c;
			$icon                  = $ok
				? '<span class="dashicons dashicons-yes-alt" style="color:#008a20"></span>'
				: '<span class="dashicons dashicons-warning" style="color:#b32d2e"></span>';
			echo '<tr><td style="width:220px">' . esc_html( $label ) . '</td><td style="width:30px">' . $icon . '</td><td>' . esc_html( $info ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
	}

	private static function render_settings() {
		$locked  = defined( 'GDW_KEY_DIR' );
		$blocked = defined( 'GDW_BACKUP_DIR' );
		echo '<h2>Settings</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="gdw_settings">';
		wp_nonce_field( 'gdw_settings' );
		echo '<table class="form-table" role="presentation">';
		self::row(
			'Deploy key folder',
			'<input name="key_dir" class="large-text code" value="' . esc_attr( GDW_Config::key_dir() ) . '"' . ( $locked ? ' disabled' : '' ) . '>',
			$locked ? 'Set by <code>GDW_KEY_DIR</code> in wp-config.php.' : 'Where generated keys are stored. Must be outside the web root and writable by the PHP user.'
		);
		self::row(
			'Backup folder',
			'<input name="backup_dir" class="large-text code" value="' . esc_attr( GDW_Config::backup_dir() ) . '"' . ( $blocked ? ' disabled' : '' ) . '>',
			$blocked ? 'Set by <code>GDW_BACKUP_DIR</code> in wp-config.php.' : 'Setup saves a .tar.gz of a folder here before replacing it. Keep it outside the web root.'
		);
		echo '</table>';
		if ( ! $locked || ! $blocked ) {
			submit_button( 'Save settings' );
		}
		echo '</form>';
	}

	private static function render_help() {
		$root = esc_html( rtrim( ABSPATH, '/' ) );
		echo '<h2>WP-CLI</h2><pre style="background:#f6f7f7;padding:8px;max-width:1000px;overflow:auto">'
			. "wp git-deploy list\n"
			. "wp git-deploy inspect &lt;id&gt;\n"
			. "wp git-deploy setup &lt;id&gt; --use=repo|folder [--branch=&lt;b&gt;] [--message=&lt;m&gt;] [--force] [--no-backup]\n"
			. "wp git-deploy pull [&lt;id&gt;...]       # deploy now\n"
			. "wp git-deploy log &lt;id&gt; [--count=3]\n\n"
			. "# queue mode: run as the user that owns the repository folders\n"
			. "* * * * * wp --path={$root} git-deploy run-pending --quiet"
			. '</pre>';
	}

	/* ================================================================ */
	/* Handlers                                                         */
	/* ================================================================ */

	public static function handle_save() {
		self::guard( 'save' );

		$in   = isset( $_POST['repo'] ) && is_array( $_POST['repo'] ) ? wp_unslash( $_POST['repo'] ) : [];
		$orig = GDW_Config::sanitize_id( wp_unslash( $_POST['original_id'] ?? '' ) );
		$back = $orig ? [ 'view' => 'edit', 'repo' => $orig ] : [ 'view' => 'edit' ];

		[ $path, $err ] = self::resolve_target( wp_unslash( $_POST ) );
		if ( $err ) {
			self::done( 'error', $err, '', $back );
		}

		$id = $orig ?: GDW_Config::sanitize_id( $in['id'] ?? '' );
		if ( ! $id ) {
			$base = GDW_Config::sanitize_id( basename( $path ) ) ?: 'repo';
			$id   = $base;
			for ( $n = 2; GDW_Config::get( $id ); $n++ ) {
				$id = "{$base}-{$n}";
			}
		}

		$other = GDW_Config::repo_for_path( $path, $id );
		if ( $other ) {
			self::done( 'error', "wp-content/{$path} is already managed by '{$other}'.", '', $back );
		}

		$existing = GDW_Config::get( $id );
		if ( ! $orig && $existing ) {
			self::done( 'error', "A repository with ID '{$id}' already exists.", '', $back );
		}
		if ( $existing && $existing['locked'] ) {
			self::done( 'error', 'This repository is defined in wp-config.php.', '', $back );
		}

		$raw_url = trim( (string) ( $in['repo_url'] ?? '' ) );
		if ( '' === $raw_url ) {
			// Folder is already a checkout: default to its origin.
			$raw_url = GDW_Config::sanitize_repo_url( GDW_Setup::origin_of( WP_CONTENT_DIR . '/' . $path ) );
		}
		$repo    = GDW_Config::normalize(
			$id,
			[
				'path'     => $path,
				'repo_url' => $raw_url,
				'branch'   => $in['branch'] ?? '',
				'mode'     => $in['mode'] ?? '',
				'ssh_key'  => $in['ssh_key'] ?? '',
				'secret'   => sanitize_text_field( $in['secret'] ?? '' ) ?: wp_generate_password( 40, false ),
				'enabled'  => ! empty( $in['enabled'] ),
				'run_hook' => ! empty( $in['run_hook'] ),
				'protect_changes' => ! empty( $in['protect_changes'] ),
			]
		);

		if ( '' === $repo['path'] ) {
			self::done( 'error', 'The folder must be a path inside wp-content, like themes/my-theme.', '', $back );
		}
		if ( '' !== $raw_url && '' === $repo['repo_url'] ) {
			self::done( 'error', 'The repository URL is not valid. Use git@github.com:org/repo.git or https://…', '', $back );
		}

		GDW_Config::save_repo( $repo );
		if ( function_exists( 'proc_open' ) ) {
			GDW_Setup::inspect( GDW_Config::get( $id ) );
		}
		self::done( 'success', $orig ? 'Repository saved.' : 'Repository added. Next, choose how to set up the folder.', '', [ 'view' => 'edit', 'repo' => $id ], 'setup' );
	}

	public static function handle_delete() {
		self::guard( 'delete' );
		$repo = self::posted_repo();
		if ( $repo['locked'] ) {
			self::done( 'error', 'This repository is defined in wp-config.php.' );
		}
		GDW_Config::delete_repo( $repo['id'] );
		self::done( 'success', "Removed {$repo['id']}. Files on disk were not changed." );
	}

	public static function handle_deploy() {
		self::guard( 'deploy' );
		$repo = self::posted_repo();
		$e    = GDW_Deployer::run( $repo, 'admin' );
		GDW_Setup::forget( $repo['id'] );
		self::done(
			$e['ok'] ? 'success' : 'error',
			$e['ok'] ? "Deployed {$repo['id']}: {$e['head']}" : "Deploy of {$repo['id']} failed.",
			$e['output'],
			[ 'view' => 'edit', 'repo' => $repo['id'] ]
		);
	}

	public static function handle_test() {
		self::guard( 'test' );
		$repo        = self::posted_repo();
		[ $ok, $msg ] = GDW_Deployer::test( $repo );
		self::done( $ok ? 'success' : 'error', $ok ? $msg : 'Connection failed.', $ok ? '' : $msg, [ 'view' => 'edit', 'repo' => $repo['id'] ] );
	}

	public static function handle_keygen() {
		self::guard( 'keygen' );
		$repo         = self::posted_repo();
		[ $ok, $msg ] = GDW_Deployer::generate_key( $repo );
		self::done(
			$ok ? 'success' : 'error',
			$ok ? $msg . ' Add the public key to GitHub below.' : 'Could not generate a key.',
			$ok ? '' : $msg,
			[ 'view' => 'edit', 'repo' => $repo['id'] ]
		);
	}

	public static function handle_settings() {
		self::guard( 'settings' );
		$settings = get_option( GDW_Config::SETTINGS, [] );
		$settings = is_array( $settings ) ? $settings : [];
		foreach ( [ 'key_dir' => 'GDW_KEY_DIR', 'backup_dir' => 'GDW_BACKUP_DIR' ] as $field => $const ) {
			if ( defined( $const ) || ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$dir = GDW_Config::sanitize_abs_path( wp_unslash( $_POST[ $field ] ) );
			if ( '' === $dir || false !== strpos( $dir, '..' ) ) {
				self::done( 'error', 'Please enter absolute folder paths.' );
			}
			$settings[ $field ] = rtrim( $dir, '/' );
		}
		update_option( GDW_Config::SETTINGS, $settings, false );
		$problems = GDW_Deployer::ensure_dirs();
		$problems
			? self::done( 'warning', 'Settings saved, but a folder could not be prepared.', implode( "\n", $problems ) )
			: self::done( 'success', 'Settings saved. Both folders are ready.' );
	}

	/* ================================================================ */
	/* Helpers                                                          */
	/* ================================================================ */

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You are not allowed to do this.', 403 );
		}
		check_admin_referer( 'gdw_' . $action );
	}

	private static function posted_repo() {
		$id   = GDW_Config::sanitize_id( wp_unslash( $_POST['repo'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$repo = $id ? GDW_Config::get( $id ) : null;
		if ( ! $repo ) {
			self::done( 'error', 'Repository not found.' );
		}
		return $repo;
	}

	private static function done( $type, $msg, $detail = '', array $args = [], $fragment = '' ) {
		set_transient( 'gdw_notice_' . get_current_user_id(), compact( 'type', 'msg', 'detail' ), 120 );
		wp_safe_redirect( self::url( $args ) . ( $fragment ? '#' . $fragment : '' ) );
		exit;
	}

	private static function notice() {
		$key = 'gdw_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( ! $n ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-' . esc_attr( $n['type'] ) . ' is-dismissible"><p>' . esc_html( $n['msg'] ) . '</p>';
		if ( '' !== $n['detail'] ) {
			echo '<pre style="white-space:pre-wrap;max-height:300px;overflow:auto">' . esc_html( $n['detail'] ) . '</pre>';
		}
		echo '</div>';
	}

	/** A small POST form with a nonce (never nested inside another form). */
	private static function button( $action, $id, $label, $class = 'button', $confirm = '' ) {
		$onsubmit = $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . $onsubmit . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<input type="hidden" name="action" value="gdw_' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="repo" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'gdw_' . $action );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** $field and $desc must already be escaped. */
	private static function row( $label, $field, $desc = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( '' !== $desc ) {
			echo '<p class="description">' . $desc . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</td></tr>';
	}

	private static function entry_summary( $e ) {
		if ( ! $e ) {
			return '&mdash;';
		}
		$badge = $e['ok']
			? '<span style="color:#008a20;font-weight:600">OK</span>'
			: '<span style="color:#b32d2e;font-weight:600">FAILED</span>';
		return $badge . ' ' . esc_html( human_time_diff( $e['time'] ) . ' ago via ' . $e['trigger'] )
			. ( '' !== $e['head'] ? ' &middot; <code>' . esc_html( $e['head'] ) . '</code>' : '' );
	}

	/** @return string[] plugin folder names */
	private static function plugin_dirs() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$dirs = [];
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( '.' !== dirname( $file ) ) {
				$dirs[ dirname( $file ) ] = true;
			}
		}
		return array_keys( $dirs );
	}

	/** Radio choice: existing/new theme, existing/new plugin, or another folder. */
	private static function target_field( array $r, $dis ) {
		$themes  = wp_get_themes();
		$plugins = self::plugin_dirs();
		$path    = $r['path'];
		$type    = 'theme';
		$sel     = get_stylesheet();

		if ( preg_match( '#^themes/([^/]+)$#', $path, $m ) && isset( $themes[ $m[1] ] ) ) {
			$sel = $m[1];
		} elseif ( preg_match( '#^plugins/([^/]+)$#', $path, $m ) && in_array( $m[1], $plugins, true ) ) {
			$type = 'plugin';
			$sel  = $m[1];
		} elseif ( '' !== $path ) {
			$type = 'custom';
		}

		$pick  = function ( $val ) {
			return ' onfocus="document.getElementById(\'gdw-t-' . $val . '\').checked=true"';
		};
		$radio = function ( $val, $label, $control ) use ( $type, $dis ) {
			return '<p style="margin:0 0 8px"><label style="display:inline-block;min-width:170px"><input type="radio" name="target" id="gdw-t-' . $val . '" value="' . $val . '"'
				. checked( $type, $val, false ) . $dis . '> ' . esc_html( $label ) . '</label> ' . $control . '</p>';
		};

		$theme_opts = '';
		foreach ( $themes as $slug => $theme ) {
			$flag        = $slug === get_stylesheet() ? ' (active)' : '';
			$theme_opts .= '<option value="' . esc_attr( $slug ) . '"' . selected( $sel, $slug, false ) . '>' . esc_html( $theme->get( 'Name' ) . $flag . ' — themes/' . $slug ) . '</option>';
		}
		$plugin_opts = '';
		foreach ( $plugins as $slug ) {
			$plugin_opts .= '<option value="' . esc_attr( $slug ) . '"' . selected( $sel, $slug, false ) . '>plugins/' . esc_html( $slug ) . '</option>';
		}
		$slug_input = function ( $name, $val ) use ( $pick, $dis ) {
			return '<input name="' . $name . '" class="regular-text" pattern="[A-Za-z0-9_][A-Za-z0-9._\-]*" placeholder="my-folder"' . $pick( $val ) . $dis . '>';
		};

		return $radio( 'theme', 'Existing theme', '<select name="target_theme"' . $pick( 'theme' ) . $dis . '>' . $theme_opts . '</select>' )
			. $radio( 'new_theme', 'New theme', '<code>themes/</code>' . $slug_input( 'target_new_theme', 'new_theme' ) )
			. $radio( 'plugin', 'Existing plugin', $plugin_opts ? '<select name="target_plugin"' . $pick( 'plugin' ) . $dis . '>' . $plugin_opts . '</select>' : '<em>none installed in a folder</em>' )
			. $radio( 'new_plugin', 'New plugin', '<code>plugins/</code>' . $slug_input( 'target_new_plugin', 'new_plugin' ) )
			. $radio( 'custom', 'Other folder', '<code>wp-content/</code><input name="target_custom" class="regular-text" value="' . esc_attr( 'custom' === $type ? $path : '' ) . '" placeholder="mu-plugins or themes/child/assets"' . $pick( 'custom' ) . $dis . '>' );
	}

	/** @return array{0:string,1:string} relative path, error */
	private static function resolve_target( array $post ) {
		$slug = function ( $v ) {
			$v = trim( (string) $v );
			return preg_match( '/^[A-Za-z0-9_][A-Za-z0-9._-]*$/', $v ) ? $v : '';
		};
		switch ( sanitize_key( $post['target'] ?? '' ) ) {
			case 'theme':
				$s = $slug( $post['target_theme'] ?? '' );
				return $s && wp_get_theme( $s )->exists() ? [ "themes/{$s}", '' ] : [ '', 'Choose an installed theme.' ];
			case 'plugin':
				$s = $slug( $post['target_plugin'] ?? '' );
				return $s && in_array( $s, self::plugin_dirs(), true ) ? [ "plugins/{$s}", '' ] : [ '', 'Choose an installed plugin.' ];
			case 'new_theme':
			case 'new_plugin':
				$kind = 'new_theme' === $post['target'] ? 'themes' : 'plugins';
				$s    = $slug( $post[ 'target_' . $post['target'] ] ?? '' );
				if ( ! $s ) {
					return [ '', 'Enter a folder name (letters, numbers, dots, - and _).' ];
				}
				if ( GDW_Setup::has_files( WP_CONTENT_DIR . "/{$kind}/{$s}" ) ) {
					return [ '', "{$kind}/{$s} already exists and is not empty. Choose it as an existing " . rtrim( $kind, 's' ) . ' instead.' ];
				}
				return [ "{$kind}/{$s}", '' ];
			case 'custom':
				$p = GDW_Config::sanitize_path( $post['target_custom'] ?? '' );
				return $p ? [ $p, '' ] : [ '', 'Enter a folder inside wp-content that is at least two levels deep, like themes/my-theme (mu-plugins is also allowed).' ];
		}
		return [ '', 'Choose a folder.' ];
	}

	private static function render_setup( array $r ) {
		echo '<hr><h2 id="setup">Setup</h2>';
		$s = GDW_Setup::cached( $r['id'] );

		if ( ! $s ) {
			echo '<p>Compare this folder with the GitHub repository to see how to connect them.</p><p>';
			self::button( 'inspect', $r['id'], 'Inspect folder and repository', 'button button-primary' );
			echo '</p>';
			return;
		}

		[ $type, $advice ] = GDW_Setup::verdict( $s, $r );
		$f                 = GDW_Setup::flags( $s );
		$b                 = $r['branch'];

		echo '<table class="widefat striped" style="max-width:1000px"><tbody>';
		foreach ( GDW_Setup::describe( $s, $r ) as $label => $text ) {
			echo '<tr><td style="width:140px"><strong>' . esc_html( $label ) . '</strong></td><td>' . esc_html( $text );
			if ( 'Local git' === $label && $s['dirty'] ) {
				echo '<details><summary>Show changes</summary><pre style="margin:4px 0">' . esc_html( implode( "\n", $s['dirty'] ) . ( $s['dirty_count'] > count( $s['dirty'] ) ? "\n…" : '' ) ) . '</pre></details>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline" style="max-width:980px"><p>' . esc_html( $advice ) . '</p></div>';
		echo '<p class="description">Inspected ' . esc_html( human_time_diff( $s['time'] ) ) . ' ago. ';
		self::button( 'inspect', $r['id'], 'Inspect again', 'button-link' );
		echo '</p>';

		if ( ! $f['use_repo'] && ! $f['publish'] ) {
			return;
		}

		$post = esc_url( admin_url( 'admin-post.php' ) );
		echo '<div style="display:flex;gap:16px;flex-wrap:wrap;max-width:1000px;margin-top:12px">';

		if ( $f['use_repo'] ) {
			$has   = $s['exists'] && ( ! $s['empty'] || $s['git'] );
			$warn  = "Replace everything in wp-content/{$r['path']} with {$b} from GitHub?" . ( $s['active'] ? ' This is the live theme.' : '' );
			echo '<form method="post" action="' . $post . '" class="card" style="flex:1;min-width:300px;margin:0" onsubmit="return confirm(' . esc_attr( wp_json_encode( $warn ) ) . ')">';
			echo '<input type="hidden" name="action" value="gdw_use_repo"><input type="hidden" name="repo" value="' . esc_attr( $r['id'] ) . '">';
			wp_nonce_field( 'gdw_use_repo' );
			echo '<h3>Use the repository\'s version</h3>';
			echo '<p>' . ( $has ? "The folder is replaced by {$b} from GitHub. Files that aren't in the repository are removed." : "Clones {$b} from GitHub into the folder." ) . '</p>';
			if ( $has ) {
				echo '<p><label><input type="checkbox" name="backup" value="1" checked> Back up the folder first</label><br><span class="description">' . esc_html( GDW_Config::backup_dir() ) . '</span></p>';
			}
			echo '<p><button class="button button-primary">' . ( $has ? 'Replace with GitHub version' : 'Clone into folder' ) . '</button></p></form>';
		}

		if ( $f['publish'] ) {
			$host     = wp_parse_url( home_url(), PHP_URL_HOST );
			$default  = $f['needs_choice'] ? 'from-' . sanitize_title( $host ) : $b;
			$msg      = $s['head'] ? "Changes from {$host}" : "Import from {$host}";
			$branches = '';
			foreach ( array_keys( $s['remote_branches'] ) as $rb ) {
				$branches .= '<option value="' . esc_attr( $rb ) . '">';
			}
			echo '<form method="post" action="' . $post . '" class="card" style="flex:1;min-width:300px;margin:0" onsubmit="return confirm(\'Commit this folder and push it to GitHub?\')">';
			echo '<input type="hidden" name="action" value="gdw_publish"><input type="hidden" name="repo" value="' . esc_attr( $r['id'] ) . '">';
			wp_nonce_field( 'gdw_publish' );
			echo '<h3>Publish this folder to GitHub</h3>';
			echo '<p>' . ( $s['head']
				? 'Commits the folder\'s changes and pushes its history.'
				: ( '' !== $s['remote_sha'] ? "Commits the folder as a new commit on top of {$b}. Files that exist only on GitHub are kept." : 'Creates the first commit from the folder and pushes it.' ) ) . '</p>';
			echo '<p><label>Commit message<br><input name="message" class="regular-text" value="' . esc_attr( $msg ) . '"></label></p>';
			echo '<p><label>Branch<br><input name="branch" class="regular-text" list="gdw-remote-branches" value="' . esc_attr( $default ) . '"></label><datalist id="gdw-remote-branches">' . $branches . '</datalist>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<br><span class="description">' . ( $f['needs_choice']
				? "GitHub's {$b} has history this folder doesn't, so a new branch is suggested. Merge it with a pull request and the merge deploys here."
				: "Pushing to {$b} also deploys it to any other site that tracks this branch." ) . '</span></p>';
			if ( $f['needs_choice'] ) {
				echo '<p><label><input type="checkbox" name="force" value="1" onchange="if(this.checked&&!confirm(' . esc_attr( wp_json_encode( "Overwrite the branch on GitHub with this folder? Commits on GitHub that aren't in this folder will be lost." ) ) . '))this.checked=false"> Overwrite the branch on GitHub (force push)</label></p>';
			}
			echo '<p class="description">Needs a deploy key with write access.</p>';
			echo '<p><button class="button">Publish to GitHub</button></p></form>';
		}

		echo '</div>';
	}

	public static function handle_inspect() {
		self::guard( 'inspect' );
		$repo = self::posted_repo();
		GDW_Setup::inspect( $repo );
		wp_safe_redirect( self::url( [ 'view' => 'edit', 'repo' => $repo['id'] ] ) . '#setup' );
		exit;
	}

	public static function handle_use_repo() {
		self::guard( 'use_repo' );
		$repo = self::posted_repo();
		$e    = GDW_Setup::use_repo( $repo, ! empty( $_POST['backup'] ) );
		GDW_Setup::inspect( $repo );
		self::done( $e['ok'] ? 'success' : 'error', $e['ok'] ? "The folder now matches {$repo['branch']} on GitHub." : 'Setup failed.', $e['output'], [ 'view' => 'edit', 'repo' => $repo['id'] ], 'setup' );
	}

	public static function handle_publish() {
		self::guard( 'publish' );
		$repo = self::posted_repo();
		$user = wp_get_current_user();
		$e    = GDW_Setup::publish(
			$repo,
			[
				'branch'       => sanitize_text_field( wp_unslash( $_POST['branch'] ?? '' ) ),
				'message'      => sanitize_text_field( wp_unslash( $_POST['message'] ?? '' ) ),
				'force'        => ! empty( $_POST['force'] ),
				'author_name'  => $user->display_name,
				'author_email' => $user->user_email,
			]
		);
		GDW_Setup::inspect( $repo );
		self::done( $e['ok'] ? 'success' : 'error', $e['ok'] ? "Published: {$e['head']}" : 'Publish failed.', $e['output'], [ 'view' => 'edit', 'repo' => $repo['id'] ], 'setup' );
	}
}
