<?php
/**
 * Tools → Git Deploy
 *
 * The main page has tabs (repositories, server checks, settings, WP-CLI). Each
 * repository is set up through numbered step tabs, one task per step.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GDW_Admin {

	const PAGE = 'git-deploy';

	const TABS = [
		'repos'    => 'Repositories',
		'checks'   => 'Server checks',
		'settings' => 'Settings',
		'cli'      => 'WP-CLI',
	];

	const STEPS = [
		'repository' => 'Repository',
		'key'        => 'Deploy key',
		'setup'      => 'Set up folder',
		'auto'       => 'Automatic deploys',
		'deploy'     => 'Deploy & history',
	];

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

	private static function step_url( $id, $step ) {
		return self::url( [ 'view' => 'edit', 'repo' => $id, 'step' => $step ] );
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
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="wrap gdw">';
		self::styles();
		if ( 'edit' === $view ) {
			self::render_edit( $id, $step );
		} elseif ( 'unlink' === $view ) {
			self::render_unlink( $id );
		} else {
			self::render_main( isset( self::TABS[ $tab ] ) ? $tab : 'repos' );
		}
		echo '</div>';
	}

	private static function styles() {
		?>
		<style>
			.gdw .nav-tab-wrapper { margin-bottom: 0; }
			.gdw .gdw-panel { background: #fff; border: 1px solid #c3c4c7; border-top: 0; padding: 4px 24px 20px; max-width: 1000px; box-sizing: border-box; }
			.gdw .gdw-intro { font-size: 14px; color: #50575e; max-width: 760px; margin: 16px 0; }
			.gdw .gdw-num { display: inline-block; width: 20px; height: 20px; line-height: 20px; border-radius: 50%; background: #dcdcde; color: #1d2327; text-align: center; font-size: 12px; margin-right: 6px; }
			.gdw .nav-tab-active .gdw-num { background: #2271b1; color: #fff; }
			.gdw .gdw-done .gdw-num { background: #00a32a; color: #fff; }
			.gdw .nav-tab.gdw-off { opacity: .55; cursor: not-allowed; }
			.gdw .gdw-badge { display: inline-block; min-width: 18px; padding: 0 5px; border-radius: 9px; background: #d63638; color: #fff; font-size: 11px; line-height: 18px; text-align: center; margin-left: 4px; }
			.gdw .gdw-howto { max-width: 760px; padding-left: 0; list-style: none; counter-reset: gdw; }
			.gdw .gdw-howto > li { counter-increment: gdw; position: relative; padding-left: 36px; margin-bottom: 18px; }
			.gdw .gdw-howto > li::before { content: counter(gdw); position: absolute; left: 0; top: 0; width: 24px; height: 24px; line-height: 24px; border-radius: 50%; background: #f0f0f1; text-align: center; font-weight: 600; }
			.gdw .gdw-copy-row { display: flex; gap: 8px; align-items: flex-start; margin-top: 6px; }
			.gdw .gdw-copy-row textarea, .gdw .gdw-copy-row input { flex: 1; min-width: 0; }
			.gdw .gdw-status { border-left: 4px solid #c3c4c7; background: #f6f7f7; padding: 10px 12px; margin: 16px 0; max-width: 760px; }
			.gdw .gdw-status.ok { border-color: #00a32a; }
			.gdw .gdw-status.bad { border-color: #d63638; }
			.gdw .gdw-status pre { white-space: pre-wrap; margin: 6px 0 0; }
			.gdw .gdw-nav { display: flex; justify-content: space-between; gap: 8px; border-top: 1px solid #f0f0f1; margin-top: 24px; padding-top: 16px; }
			.gdw details.gdw-advanced { margin: 8px 0 0; }
			.gdw details.gdw-advanced > summary { cursor: pointer; font-weight: 600; padding: 8px 0; }
			.gdw .gdw-danger { margin-top: 32px; padding-top: 12px; border-top: 1px solid #f0f0f1; }
			@media (max-width: 782px) { .gdw .gdw-panel { padding: 4px 12px 16px; } .gdw .gdw-copy-row { flex-direction: column; align-items: stretch; } }
		</style>
		<script>
			document.addEventListener( 'click', function ( e ) {
				var b = e.target.closest && e.target.closest( '[data-gdw-copy]' );
				if ( ! b ) { return; }
				var f = document.getElementById( b.getAttribute( 'data-gdw-copy' ) ), label = b.textContent;
				f.select();
				var ok = function () { b.textContent = 'Copied'; setTimeout( function () { b.textContent = label; }, 1500 ); };
				if ( navigator.clipboard && window.isSecureContext ) { navigator.clipboard.writeText( f.value ).then( ok ); } else { document.execCommand( 'copy' ); ok(); }
			} );
		</script>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/* Main page                                                         */
	/* ---------------------------------------------------------------- */

	private static function render_main( $tab ) {
		echo '<h1 class="wp-heading-inline">Git Deploy</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( self::url( [ 'view' => 'edit' ] ) ) . '">Add repository</a>';
		echo '<hr class="wp-header-end">';
		self::notice();

		$checks = 'checks' === $tab || 'repos' === $tab ? GDW_Deployer::checks() : [];
		$failed = count( array_filter( $checks, function ( $c ) { return ! $c[1]; } ) );

		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			$badge = 'checks' === $key && $failed ? '<span class="gdw-badge">' . (int) $failed . '</span>' : '';
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( [ 'tab' => $key ] ) ) . '">' . esc_html( $label ) . $badge . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</nav><div class="gdw-panel">';

		switch ( $tab ) {
			case 'checks':
				self::render_checks( $checks );
				break;
			case 'settings':
				self::render_settings();
				break;
			case 'cli':
				self::render_help();
				break;
			default:
				self::render_list( $failed );
		}
		echo '</div>';
	}

	private static function render_list( $failed ) {
		$repos = GDW_Config::repos();

		echo '<p class="gdw-intro">Each repository keeps one folder in <code>wp-content</code> in step with a branch on GitHub. Open a repository to continue its setup steps.</p>';
		if ( $failed ) {
			echo '<div class="notice notice-warning inline"><p>' . (int) $failed . ' server check(s) need attention. <a href="' . esc_url( self::url( [ 'tab' => 'checks' ] ) ) . '">View server checks</a>.</p></div>';
		}

		if ( ! $repos ) {
			echo '<p>No repositories yet.</p><p><a class="button button-primary" href="' . esc_url( self::url( [ 'view' => 'edit' ] ) ) . '">Add your first repository</a></p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>Repository</th><th>Branch</th><th>Status</th><th>Last deploy</th><th></th></tr></thead><tbody>';
		foreach ( $repos as $r ) {
			$log  = GDW_Deployer::log( $r['id'] );
			$next = self::next_step( $r );
			echo '<tr><td><strong><a href="' . esc_url( self::step_url( $r['id'], $next ) ) . '">' . esc_html( $r['id'] ) . '</a></strong>';
			if ( $r['locked'] ) {
				echo ' <span class="dashicons dashicons-lock" title="Defined in wp-config.php"></span>';
			}
			echo '<br><code>wp-content/' . esc_html( $r['path'] ) . '</code></td>';
			echo '<td>' . esc_html( $r['branch'] ) . '</td><td>';
			if ( 'deploy' !== $next ) {
				$n = array_search( $next, array_keys( self::STEPS ), true ) + 1;
				echo '<a href="' . esc_url( self::step_url( $r['id'], $next ) ) . '">Next: step ' . (int) $n . ', ' . esc_html( self::STEPS[ $next ] ) . '</a>';
			} elseif ( ! $r['enabled'] ) {
				echo '<em>Automatic deploys off</em>';
			} else {
				echo '<span style="color:#008a20">&#10003; Ready</span>' . ( GDW_Deployer::pending( $r['id'] ) ? ' <em>(deploy queued)</em>' : '' );
			}
			echo '</td><td>' . self::entry_summary( $log[0] ?? null ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			self::button( 'deploy', $r['id'], 'Deploy now' );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_checks( array $checks ) {
		echo '<p class="gdw-intro">What this server needs to run git for WordPress. Fix anything marked with a warning before setting up a repository.</p>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $checks as $c ) {
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
		echo '<p class="gdw-intro">Private folders on the server, shared by all repositories. Both must be outside the website\'s public folder.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="gdw_settings">';
		wp_nonce_field( 'gdw_settings' );
		echo '<table class="form-table" role="presentation">';
		self::row(
			'Deploy key folder',
			'<input name="key_dir" class="large-text code" value="' . esc_attr( GDW_Config::key_dir() ) . '"' . ( $locked ? ' disabled' : '' ) . '>',
			$locked ? 'Set by <code>GDW_KEY_DIR</code> in wp-config.php.' : 'Where generated deploy keys are stored. Must be writable by the PHP user.'
		);
		self::row(
			'Backup folder',
			'<input name="backup_dir" class="large-text code" value="' . esc_attr( GDW_Config::backup_dir() ) . '"' . ( $blocked ? ' disabled' : '' ) . '>',
			$blocked ? 'Set by <code>GDW_BACKUP_DIR</code> in wp-config.php.' : 'A .tar.gz of a folder is saved here before it is replaced.'
		);
		echo '</table>';
		if ( ! $locked || ! $blocked ) {
			submit_button( 'Save settings' );
		}
		echo '</form>';
	}

	private static function render_help() {
		$root = esc_html( rtrim( ABSPATH, '/' ) );
		echo '<p class="gdw-intro">Everything on these pages can also be done from the command line.</p>';
		echo '<pre style="background:#f6f7f7;padding:8px;overflow:auto">'
			. "wp git-deploy list\n"
			. "wp git-deploy inspect &lt;id&gt;\n"
			. "wp git-deploy setup &lt;id&gt; --use=repo|folder [--branch=&lt;b&gt;] [--message=&lt;m&gt;] [--force] [--no-backup]\n"
			. "wp git-deploy pull [&lt;id&gt;...]       # deploy now\n"
			. "wp git-deploy log &lt;id&gt; [--count=3]\n"
			. "wp git-deploy unlink &lt;id&gt; [--delete-key] [--remove-git]\n\n"
			. "# queue mode: run as the user that owns the repository folders\n"
			. "* * * * * wp --path={$root} git-deploy run-pending --quiet"
			. '</pre>';
	}

	/* ---------------------------------------------------------------- */
	/* Repository steps                                                  */
	/* ---------------------------------------------------------------- */

	/** Which steps are complete. @return array<string,bool> */
	private static function progress( array $r ) {
		$s     = GDW_Setup::cached( $r['id'] );
		$url   = self::remote_url( $r );
		$dir   = GDW_Config::abs_path( $r );
		$git   = '' !== $dir && is_dir( $dir . '/.git' );
		$log   = GDW_Deployer::log( $r['id'] );
		$has   = '' !== GDW_Deployer::public_key( $r ) || ( '' !== $r['ssh_key'] && file_exists( $r['ssh_key'] ) );
		$hooks = array_filter( $log, function ( $e ) { return in_array( $e['trigger'], [ 'webhook', 'queue' ], true ); } );

		return [
			'repository' => '' !== $url,
			// A fresh inspection is the best evidence; otherwise a working checkout implies the key works.
			'key'        => $s ? $s['remote_ok'] : ( $git && ( $has || ! GDW_Deployer::is_ssh_url( $url ) ) ),
			'setup'      => $s ? ( $s['git'] && in_array( $s['relation'], [ 'same', 'behind' ], true ) ) : $git,
			'auto'       => $r['enabled'] && (bool) $hooks,
			'deploy'     => (bool) array_filter( $log, function ( $e ) { return $e['ok']; } ),
		];
	}

	/** The first step that still needs doing, or the last step. */
	private static function next_step( array $r ) {
		foreach ( self::progress( $r ) as $step => $done ) {
			if ( ! $done ) {
				return $step;
			}
		}
		return 'deploy';
	}

	/** Configured URL, else the folder's existing origin. */
	private static function remote_url( array $r ) {
		return '' !== $r['repo_url'] ? $r['repo_url'] : GDW_Setup::origin_of( GDW_Config::abs_path( $r ) );
	}

	private static function render_edit( $id, $step ) {
		$repo = $id ? GDW_Config::get( $id ) : null;

		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; All repositories</a></p>';

		if ( $id && ! $repo ) {
			echo '<h1>Git Deploy</h1><p>Repository not found.</p>';
			return;
		}

		$new  = ! $repo;
		$r    = $repo ?: GDW_Config::normalize( '', [] );
		$done = $new ? [] : self::progress( $r );
		$step = $new ? 'repository' : ( isset( self::STEPS[ $step ] ) ? $step : self::next_step( $r ) );

		echo '<h1 class="wp-heading-inline">' . ( $new ? 'Add repository' : 'Repository: ' . esc_html( $r['id'] ) ) . '</h1>';
		if ( ! $new && ! $r['locked'] ) {
			echo ' <a class="page-title-action" href="' . esc_url( self::url( [ 'view' => 'unlink', 'repo' => $r['id'] ] ) ) . '">' . ( self::is_set_up( $done ) ? 'Unlink' : 'Cancel setup' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';
		if ( ! $new ) {
			echo '<p class="description"><code>wp-content/' . esc_html( $r['path'] ) . '</code> &larr; ' . esc_html( $r['branch'] ) . ( $r['repo_url'] ? ' of <code>' . esc_html( $r['repo_url'] ) . '</code>' : '' ) . '</p>';
		}
		self::notice();

		if ( $r['locked'] ) {
			echo '<div class="notice notice-info inline"><p>This repository is defined in <code>wp-config.php</code> (<code>GDW_REPOS</code>), so its settings can only be changed there.</p></div>';
		}

		echo '<nav class="nav-tab-wrapper">';
		$n = 0;
		foreach ( self::STEPS as $key => $label ) {
			$n++;
			$ok    = ! empty( $done[ $key ] );
			$class = 'nav-tab' . ( $key === $step ? ' nav-tab-active' : '' ) . ( $ok ? ' gdw-done' : '' );
			$inner = '<span class="gdw-num">' . ( $ok ? '&#10003;' : $n ) . '</span>' . esc_html( $label );
			if ( $new && 'repository' !== $key ) {
				echo '<span class="' . esc_attr( $class ) . ' gdw-off" title="Save the repository first">' . $inner . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			} else {
				echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::step_url( $r['id'], $key ) ) . '">' . $inner . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		echo '</nav><div class="gdw-panel">';

		switch ( $step ) {
			case 'key':
				self::step_key( $r );
				break;
			case 'setup':
				self::step_setup( $r );
				break;
			case 'auto':
				self::step_auto( $r );
				break;
			case 'deploy':
				self::step_deploy( $r );
				break;
			default:
				self::step_repository( $r, $new );
		}

		if ( ! $new ) {
			self::step_nav( $r['id'], $step );
		}
		echo '</div>';
	}

	/** Back / Next links under a step. */
	private static function step_nav( $id, $step ) {
		$keys = array_keys( self::STEPS );
		$i    = array_search( $step, $keys, true );
		echo '<div class="gdw-nav"><span>';
		if ( $i > 0 ) {
			echo '<a class="button" href="' . esc_url( self::step_url( $id, $keys[ $i - 1 ] ) ) . '">&larr; ' . esc_html( self::STEPS[ $keys[ $i - 1 ] ] ) . '</a>';
		}
		echo '</span><span>';
		if ( $i < count( $keys ) - 1 ) {
			echo '<a class="button" href="' . esc_url( self::step_url( $id, $keys[ $i + 1 ] ) ) . '">' . esc_html( self::STEPS[ $keys[ $i + 1 ] ] ) . ' &rarr;</a>';
		}
		echo '</span></div>';
	}

	/** Step 1: which folder, which repository and branch. */
	private static function step_repository( array $r, $new ) {
		$dis = $r['locked'] ? ' disabled' : '';

		echo '<p class="gdw-intro">Choose the folder on this site and the GitHub repository and branch it should follow. Nothing in the folder changes yet: you decide how to connect them in step 3.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="gdw_save"><input type="hidden" name="section" value="repository">';
		echo '<input type="hidden" name="original_id" value="' . esc_attr( $r['id'] ) . '">';
		wp_nonce_field( 'gdw_save' );
		echo '<table class="form-table" role="presentation">';

		self::row( 'Folder', self::target_field( $r, $dis ), 'It does not need to have the same name as the GitHub repository.' );

		self::row(
			'Repository URL',
			'<input name="repo[repo_url]" class="large-text code" value="' . esc_attr( $r['repo_url'] ) . '" placeholder="git@github.com:your-org/your-theme.git"' . $dis . '>',
			'On GitHub: the green <strong>Code</strong> button &rarr; <strong>SSH</strong>. Private repositories need the SSH URL; a deploy key is created for it in the next step. If the folder is already a git checkout you can leave this empty to use its <code>origin</code>.'
		);

		self::row( 'Branch', '<input name="repo[branch]" class="regular-text" value="' . esc_attr( $r['branch'] ) . '" required' . $dis . '>', 'Pushes to this branch are deployed to this site.' );

		echo '</table>';

		echo '<details class="gdw-advanced"' . ( '' !== $r['ssh_key'] ? ' open' : '' ) . '><summary>Advanced</summary><table class="form-table" role="presentation">';
		self::row(
			'ID',
			'<input name="repo[id]" class="regular-text" value="' . esc_attr( $r['id'] ) . '" pattern="[a-z0-9_\-]*"' . ( $new ? ' placeholder="from the folder name"' : ' readonly' ) . $dis . '>',
			$new ? 'Used in the webhook URL. Defaults to the folder name.' : 'Used in the webhook URL. It can\'t be changed.'
		);
		self::row(
			'SSH key path',
			'<input name="repo[ssh_key]" class="large-text code" value="' . esc_attr( $r['ssh_key'] ) . '" placeholder="' . esc_attr( GDW_Config::key_dir() . '/' . ( $r['id'] ?: '<id>' ) ) . '"' . $dis . '>',
			'Only to use a private key you manage yourself. Leave empty and one is generated for you.'
		);
		echo '</table></details>';

		if ( ! $r['locked'] ) {
			submit_button( $new ? 'Add repository and continue' : 'Save and continue' );
		}
		echo '</form>';
	}

	/** Step 2: give the user the public key to add on GitHub, then test. */
	private static function step_key( array $r ) {
		$url  = self::remote_url( $r );
		$s    = GDW_Setup::cached( $r['id'] );
		$link = GDW_Deployer::github_keys_url( $url );

		if ( '' === $url ) {
			echo '<p class="gdw-intro">Set a repository URL in step 1 first.</p>';
			return;
		}

		if ( ! GDW_Deployer::is_ssh_url( $url ) ) {
			echo '<p class="gdw-intro">This repository uses an HTTPS URL, which needs no deploy key, but only works for <strong>public</strong> repositories. For a private repository, change the URL in step 1 to the SSH form (<code>git@github.com:org/repo.git</code>).</p>';
		} else {
			echo '<p class="gdw-intro">GitHub only lets this server read the repository once it trusts the server\'s <strong>deploy key</strong>. A deploy key gives access to this one repository only.</p>';
			$pub = GDW_Deployer::public_key( $r );

			if ( '' === $pub && '' !== $r['ssh_key'] && file_exists( $r['ssh_key'] ) ) {
				echo '<p>Using your own key at <code>' . esc_html( $r['ssh_key'] ) . '</code>'
					. ( is_readable( $r['ssh_key'] ) ? '' : ' <strong>(not readable by ' . esc_html( GDW_Deployer::whoami() ) . ')</strong>' )
					. '. Its public key (<code>' . esc_html( $r['ssh_key'] ) . '.pub</code>) was not found, so add that to GitHub yourself.</p>';
			} elseif ( '' === $pub ) {
				echo '<div class="gdw-status"><p style="margin-top:0">No deploy key has been created for this repository yet.</p>';
				self::button( 'keygen', $r['id'], 'Create deploy key', 'button button-primary' );
				echo '</div>';
			} else {
				$host = wp_parse_url( home_url(), PHP_URL_HOST );
				echo '<ol class="gdw-howto">';
				echo '<li><strong>Copy this public key.</strong> It is safe to share; the private half never leaves this server.'
					. '<div class="gdw-copy-row"><textarea id="gdw-pubkey" readonly class="code" rows="3" onclick="this.select()">' . esc_textarea( $pub ) . '</textarea>'
					. '<button type="button" class="button" data-gdw-copy="gdw-pubkey">Copy key</button></div></li>';
				echo '<li><strong>Add it on GitHub.</strong> '
					. ( $link ? '<a class="button" href="' . esc_url( $link ) . '" target="_blank" rel="noopener">Open "Add deploy key" on GitHub &#8599;</a>' : 'In the repository open <em>Settings &rarr; Deploy keys &rarr; Add deploy key</em>.' )
					. '<br><span class="description">Title: anything, e.g. <code>' . esc_html( $host ) . '</code>. Key: paste. Tick <em>Allow write access</em> only if you will publish from this server in step 3.</span></li>';
				echo '<li><strong>Test the connection</strong> below.</li>';
				echo '</ol>';
			}
		}

		if ( $s ) {
			echo $s['remote_ok'] // phpcs:ignore WordPress.Security.EscapeOutput
				? '<div class="gdw-status ok"><strong>&#10003; Connected.</strong> This server can read the repository.</div>'
				: '<div class="gdw-status bad"><strong>Not connected yet.</strong>' . ( GDW_Deployer::is_ssh_url( $url ) ? ' If you just added the key, test again.' : '' ) . '<pre>' . esc_html( $s['remote_error'] ) . '</pre></div>';
		}
		echo '<p>';
		self::button( 'test', $r['id'], 'Test connection', $s && $s['remote_ok'] ? 'button' : 'button button-primary' );
		echo '</p>';
	}

	/** Step 3: compare the folder with GitHub and pick a direction. */
	private static function step_setup( array $r ) {
		echo '<p class="gdw-intro">Compare the folder with GitHub and choose which version to keep. Nothing changes until you pick an action.</p>';
		$s = GDW_Setup::cached( $r['id'] );

		if ( ! $s ) {
			echo '<p>';
			self::button( 'inspect', $r['id'], 'Compare folder with GitHub', 'button button-primary' );
			echo '</p>';
			return;
		}

		[ $type, $advice ] = GDW_Setup::verdict( $s, $r );
		$f                 = GDW_Setup::flags( $s );
		$b                 = $r['branch'];

		echo '<table class="widefat striped"><tbody>';
		foreach ( GDW_Setup::describe( $s, $r ) as $label => $text ) {
			echo '<tr><td style="width:140px"><strong>' . esc_html( $label ) . '</strong></td><td>' . esc_html( $text );
			if ( 'Local git' === $label && $s['dirty'] ) {
				echo '<details><summary>Show changes</summary><pre style="margin:4px 0">' . esc_html( implode( "\n", $s['dirty'] ) . ( $s['dirty_count'] > count( $s['dirty'] ) ? "\n…" : '' ) ) . '</pre></details>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( $advice );
		if ( ! $s['remote_ok'] ) {
			echo ' <a href="' . esc_url( self::step_url( $r['id'], 'key' ) ) . '">Go to step 2, Deploy key</a>.';
		}
		echo '</p></div>';
		echo '<p class="description">Compared ' . esc_html( human_time_diff( $s['time'] ) ) . ' ago. ';
		self::button( 'inspect', $r['id'], 'Compare again', 'button-link' );
		echo '</p>';

		if ( ! $f['use_repo'] && ! $f['publish'] ) {
			return;
		}

		$post = esc_url( admin_url( 'admin-post.php' ) );
		echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px">';

		if ( $f['use_repo'] ) {
			$has   = $s['exists'] && ( ! $s['empty'] || $s['git'] );
			$warn  = "Replace everything in wp-content/{$r['path']} with {$b} from GitHub?" . ( $s['active'] ? ' This is the live theme.' : '' );
			echo '<form method="post" action="' . $post . '" class="card" style="flex:1;min-width:260px;margin:0" onsubmit="return confirm(' . esc_attr( wp_json_encode( $warn ) ) . ')">';
			echo '<input type="hidden" name="action" value="gdw_use_repo"><input type="hidden" name="repo" value="' . esc_attr( $r['id'] ) . '">';
			wp_nonce_field( 'gdw_use_repo' );
			echo '<h3>Use the GitHub version</h3>';
			echo '<p>' . ( $has ? "The folder is replaced by {$b} from GitHub. Files that aren't in the repository are removed." : "Downloads {$b} from GitHub into the folder." ) . '</p>';
			if ( $has ) {
				echo '<p><label><input type="checkbox" name="backup" value="1" checked> Back up the folder first</label><br><span class="description">' . esc_html( GDW_Config::backup_dir() ) . '</span></p>';
			}
			echo '<p><button class="button button-primary">' . ( $has ? 'Replace with GitHub version' : 'Download into folder' ) . '</button></p></form>';
		}

		if ( $f['publish'] ) {
			$host     = wp_parse_url( home_url(), PHP_URL_HOST );
			$default  = $f['needs_choice'] ? 'from-' . sanitize_title( $host ) : $b;
			$msg      = $s['head'] ? "Changes from {$host}" : "Import from {$host}";
			$branches = '';
			foreach ( array_keys( $s['remote_branches'] ) as $rb ) {
				$branches .= '<option value="' . esc_attr( $rb ) . '">';
			}
			echo '<form method="post" action="' . $post . '" class="card" style="flex:1;min-width:260px;margin:0" onsubmit="return confirm(\'Commit this folder and push it to GitHub?\')">';
			echo '<input type="hidden" name="action" value="gdw_publish"><input type="hidden" name="repo" value="' . esc_attr( $r['id'] ) . '">';
			wp_nonce_field( 'gdw_publish' );
			echo '<h3>Send this folder to GitHub</h3>';
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
			echo '<p class="description">Needs the deploy key to have write access (step 2).</p>';
			echo '<p><button class="button">Push to GitHub</button></p></form>';
		}

		echo '</div>';
	}

	/** Step 4: webhook, secret, mode and options. */
	private static function step_auto( array $r ) {
		$dis   = $r['locked'] ? ' disabled' : '';
		$gh    = GDW_Deployer::github_repo_url( self::remote_url( $r ) );
		$hooks = '' !== $gh ? $gh . '/settings/hooks/new' : '';

		echo '<p class="gdw-intro">Make every push to <strong>' . esc_html( $r['branch'] ) . '</strong> deploy to this site automatically. GitHub calls the webhook below, and the secret proves the call came from GitHub.</p>';

		echo '<ol class="gdw-howto">';
		echo '<li><strong>Open the webhook form on GitHub.</strong> '
			. ( $hooks ? '<a class="button" href="' . esc_url( $hooks ) . '" target="_blank" rel="noopener">Open "Add webhook" on GitHub &#8599;</a>' : 'In the repository open <em>Settings &rarr; Webhooks &rarr; Add webhook</em>.' ) . '</li>';
		echo '<li><strong>Payload URL</strong><div class="gdw-copy-row"><input id="gdw-hook" readonly class="code" onclick="this.select()" value="' . esc_attr( GDW_Config::webhook_url( $r['id'] ) ) . '">'
			. '<button type="button" class="button" data-gdw-copy="gdw-hook">Copy</button></div></li>';
		echo '<li><strong>Content type:</strong> <code>application/json</code></li>';
		echo '<li><strong>Secret</strong><div class="gdw-copy-row"><input id="gdw-secret-copy" readonly class="code" onclick="this.select()" value="' . esc_attr( $r['secret'] ) . '">'
			. '<button type="button" class="button" data-gdw-copy="gdw-secret-copy">Copy</button></div></li>';
		echo '<li><strong>Which events:</strong> "Just the push event". Then click <em>Add webhook</em>.</li>';
		echo '</ol>';
		echo '<p class="description">Prefer GitHub Actions? See <code>examples/deploy.yml</code> in the plugin folder. Use the webhook or Actions, not both.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="gdw_save"><input type="hidden" name="section" value="auto">';
		echo '<input type="hidden" name="original_id" value="' . esc_attr( $r['id'] ) . '">';
		wp_nonce_field( 'gdw_save' );
		echo '<details class="gdw-advanced"><summary>Options</summary><table class="form-table" role="presentation">';

		self::row(
			'Behaviour',
			'<label><input type="checkbox" name="repo[enabled]" value="1"' . checked( $r['enabled'], true, false ) . $dis . '> Accept webhooks (turn off to pause automatic deploys)</label><br>'
				. '<label><input type="checkbox" name="repo[protect_changes]" value="1"' . checked( $r['protect_changes'], true, false ) . $dis . '> Protect local changes: skip automatic deploys while the folder has uncommitted edits, instead of discarding them</label><br>'
				. '<label><input type="checkbox" name="repo[run_hook]" value="1"' . checked( $r['run_hook'], true, false ) . $dis . '> Run <code>.deploy/deploy.sh</code> from the repository after each deploy</label>'
		);

		$gen_js = "(function(i){var a=new Uint8Array(24);crypto.getRandomValues(a);i.value=Array.from(a,function(b){return b.toString(16).padStart(2,'0')}).join('');})(document.getElementById('gdw-secret'))";
		self::row(
			'Secret',
			'<input id="gdw-secret" name="repo[secret]" class="regular-text code" value="' . esc_attr( $r['secret'] ) . '" autocomplete="off"' . $dis . '> '
				. ( $r['locked'] ? '' : '<button type="button" class="button" onclick="' . esc_attr( $gen_js ) . '">New secret</button>' ),
			'If you change it, update it on GitHub too.'
		);

		$modes = [
			'direct' => 'Direct: deploy as soon as the webhook arrives (recommended)',
			'queue'  => 'Queue: a WP-CLI cron job deploys within a minute',
		];
		$opts  = '';
		foreach ( $modes as $val => $label ) {
			$opts .= '<option value="' . esc_attr( $val ) . '"' . selected( $r['mode'], $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		self::row( 'Mode', '<select name="repo[mode]"' . $dis . '>' . $opts . '</select>', 'Use Queue when the PHP user can\'t write to the folder. It needs this cron job: <code>* * * * * wp --path=' . esc_html( rtrim( ABSPATH, '/' ) ) . ' git-deploy run-pending --quiet</code>' );

		echo '</table>';
		if ( ! $r['locked'] ) {
			submit_button( 'Save options' );
		}
		echo '</details></form>';
	}

	/** Step 5: manual deploy, log and removal. */
	private static function step_deploy( array $r ) {
		echo '<p class="gdw-intro">Deploy by hand, and see what happened on recent deploys.</p>';
		echo '<p><strong>Folder is at:</strong> <code>' . esc_html( GDW_Deployer::status( $r ) ) . '</code></p>';
		$pending = GDW_Deployer::pending( $r['id'] );
		if ( $pending ) {
			echo '<p><em>Queued ' . esc_html( human_time_diff( $pending['time'] ) ) . ' ago, waiting for the WP-CLI cron.</em></p>';
		}
		echo '<p>';
		self::button( 'deploy', $r['id'], 'Deploy now', 'button button-primary', 'Make this folder match ' . $r['branch'] . ' on GitHub? Uncommitted changes in the folder will be discarded.' );
		echo '</p>';

		echo '<h2>Recent deploys</h2>';
		$log = GDW_Deployer::log( $r['id'] );
		if ( ! $log ) {
			echo '<p>None yet.</p>';
		}
		foreach ( $log as $e ) {
			echo '<details style="margin-bottom:6px"><summary>' . self::entry_summary( $e ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;max-height:400px;overflow:auto">' . esc_html( $e['output'] ) . '</pre></details>';
		}

		if ( ! $r['locked'] ) {
			echo '<div class="gdw-danger"><h3>Unlink</h3><p class="description">Stop deploying from GitHub to this folder. The folder\'s files are kept.</p>';
			echo '<a class="button button-link-delete" href="' . esc_url( self::url( [ 'view' => 'unlink', 'repo' => $r['id'] ] ) ) . '">Unlink repository&hellip;</a></div>';
		}
	}

	/** Steps 1–3 done: the folder is linked to GitHub, not just configured. */
	private static function is_set_up( array $done ) {
		return ! empty( $done['repository'] ) && ! empty( $done['key'] ) && ! empty( $done['setup'] );
	}

	/** Confirmation page for cancelling setup or unlinking a folder. */
	private static function render_unlink( $id ) {
		$r = $id ? GDW_Config::get( $id ) : null;
		if ( ! $r ) {
			echo '<p><a href="' . esc_url( self::url() ) . '">&larr; All repositories</a></p><h1>Git Deploy</h1><p>Repository not found.</p>';
			return;
		}
		echo '<p><a href="' . esc_url( self::url( [ 'view' => 'edit', 'repo' => $r['id'] ] ) ) . '">&larr; Back to ' . esc_html( $r['id'] ) . '</a></p>';

		$cancel = ! self::is_set_up( self::progress( $r ) );
		$verb   = $cancel ? 'Cancel setup' : 'Unlink';
		echo '<h1>' . esc_html( $verb . ': ' . $r['id'] ) . '</h1>';
		self::notice();

		if ( $r['locked'] ) {
			echo '<div class="notice notice-info inline"><p>This repository is defined in <code>wp-config.php</code> (<code>GDW_REPOS</code>). Remove it there instead.</p></div>';
			return;
		}

		$key    = GDW_Setup::own_key( $r );
		$git    = GDW_Setup::git_dir( $r );
		$gh     = GDW_Deployer::github_repo_url( self::remote_url( $r ) );
		$active = in_array( $r['path'], [ 'themes/' . get_stylesheet(), 'themes/' . get_template() ], true );

		echo '<div class="gdw-panel" style="border-top:1px solid #c3c4c7">';
		echo '<p class="gdw-intro">' . ( $cancel
			? 'Stop setting up this repository and forget its settings. You can add it again at any time.'
			: 'Stop deploying from GitHub to this folder. You can link it again at any time by adding the repository again.' ) . '</p>';
		echo '<p>The folder <code>wp-content/' . esc_html( $r['path'] ) . '</code> and its files stay as they are'
			. ( $active ? ', so the site keeps using this theme' : '' ) . '. Webhook calls for <code>' . esc_html( $r['id'] ) . '</code> will be rejected, and its deploy history is deleted.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="gdw_delete"><input type="hidden" name="repo" value="' . esc_attr( $r['id'] ) . '">';
		wp_nonce_field( 'gdw_delete' );

		if ( $key || $git ) {
			echo '<h3>Also clean up</h3>';
		}
		if ( $key ) {
			echo '<p><label><input type="checkbox" name="delete_key" value="1" checked> Delete this site\'s deploy key</label><br>'
				. '<span class="description"><code>' . esc_html( $key ) . '</code>. Without it this server can no longer reach the repository.</span></p>';
		}
		if ( $git ) {
			echo '<p><label><input type="checkbox" name="remove_git" value="1"> Remove git from the folder</label><br>'
				. '<span class="description">Deletes <code>.git</code> so it becomes a plain folder again, with no history and no link to GitHub. '
				. 'A backup of the whole folder is saved to <code>' . esc_html( GDW_Config::backup_dir() ) . '</code> first.</span></p>';
		}

		echo '<h3>On GitHub</h3><p>This site can\'t change GitHub for you. To finish, remove these from the repository:</p><ul style="list-style:disc;padding-left:20px">';
		echo '<li>' . ( $gh ? '<a href="' . esc_url( $gh . '/settings/keys' ) . '" target="_blank" rel="noopener">the deploy key &#8599;</a>' : 'the deploy key (Settings &rarr; Deploy keys)' ) . '</li>';
		echo '<li>' . ( $gh ? '<a href="' . esc_url( $gh . '/settings/hooks' ) . '" target="_blank" rel="noopener">the webhook &#8599;</a>' : 'the webhook (Settings &rarr; Webhooks)' ) . ', or the deploy workflow if you used GitHub Actions</li>';
		echo '</ul>';

		echo '<div class="gdw-nav"><a class="button" href="' . esc_url( self::url( [ 'view' => 'edit', 'repo' => $r['id'] ] ) ) . '">Keep it</a>';
		echo '<button type="submit" class="button button-primary">' . esc_html( $verb ) . '</button></div>';
		echo '</form></div>';
	}

	/* ================================================================ */
	/* Handlers                                                         */
	/* ================================================================ */

	public static function handle_save() {
		self::guard( 'save' );

		$in      = isset( $_POST['repo'] ) && is_array( $_POST['repo'] ) ? wp_unslash( $_POST['repo'] ) : [];
		$orig    = GDW_Config::sanitize_id( wp_unslash( $_POST['original_id'] ?? '' ) );
		$section = 'auto' === sanitize_key( $_POST['section'] ?? '' ) ? 'auto' : 'repository';
		$back    = $orig ? [ 'view' => 'edit', 'repo' => $orig, 'step' => $section ] : [ 'view' => 'edit' ];

		$current = $orig ? GDW_Config::get( $orig ) : null;
		if ( $orig && ! $current ) {
			self::done( 'error', 'Repository not found.' );
		}
		if ( $current && $current['locked'] ) {
			self::done( 'error', 'This repository is defined in wp-config.php.', '', $back );
		}

		if ( 'auto' === $section ) {
			if ( ! $current ) {
				self::done( 'error', 'Save the repository first.', '', $back );
			}
			$repo = GDW_Config::normalize(
				$orig,
				array_merge(
					$current,
					[
						'mode'            => $in['mode'] ?? '',
						'secret'          => sanitize_text_field( $in['secret'] ?? '' ) ?: wp_generate_password( 40, false ),
						'enabled'         => ! empty( $in['enabled'] ),
						'run_hook'        => ! empty( $in['run_hook'] ),
						'protect_changes' => ! empty( $in['protect_changes'] ),
					]
				)
			);
			GDW_Config::save_repo( $repo );
			self::done( 'success', 'Options saved.', '', $back );
		}

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
		if ( ! $orig && GDW_Config::get( $id ) ) {
			self::done( 'error', "A repository with ID '{$id}' already exists.", '', $back );
		}

		$raw_url = trim( (string) ( $in['repo_url'] ?? '' ) );
		if ( '' === $raw_url ) {
			// Folder is already a checkout: default to its origin.
			$raw_url = GDW_Config::sanitize_repo_url( GDW_Setup::origin_of( WP_CONTENT_DIR . '/' . $path ) );
		}
		$repo = GDW_Config::normalize(
			$id,
			array_merge(
				$current ?: [ 'secret' => wp_generate_password( 40, false ) ],
				[
					'path'     => $path,
					'repo_url' => $raw_url,
					'branch'   => $in['branch'] ?? '',
					'ssh_key'  => $in['ssh_key'] ?? '',
				]
			)
		);

		if ( '' === $repo['path'] ) {
			self::done( 'error', 'The folder must be a path inside wp-content, like themes/my-theme.', '', $back );
		}
		if ( '' !== $raw_url && '' === $repo['repo_url'] ) {
			self::done( 'error', 'The repository URL is not valid. Use git@github.com:org/repo.git or https://…', '', $back );
		}

		GDW_Config::save_repo( $repo );
		$repo = GDW_Config::get( $id );
		$msg  = $orig ? 'Repository saved.' : 'Repository added.';
		$next = 'key';

		if ( function_exists( 'proc_open' ) ) {
			// An SSH URL can't connect without a key, so create one now for step 2.
			$key = GDW_Config::ssh_key( $repo );
			if ( GDW_Deployer::is_ssh_url( $repo['repo_url'] ) && ( '' === $key || ! file_exists( $key ) ) ) {
				[ $ok, $out ] = GDW_Deployer::generate_key( $repo );
				$msg         .= $ok ? ' A deploy key was created: add it to GitHub below.' : " The deploy key could not be created: {$out}";
			}
			if ( GDW_Setup::inspect( $repo )['remote_ok'] ) {
				$msg .= ' GitHub is reachable, so the next step is setting up the folder.';
				$next = 'setup';
			}
		}
		self::done( 'success', $msg, '', [ 'view' => 'edit', 'repo' => $id, 'step' => $next ] );
	}

	public static function handle_delete() {
		self::guard( 'delete' );
		$repo = self::posted_repo();
		if ( $repo['locked'] ) {
			self::done( 'error', 'This repository is defined in wp-config.php.' );
		}
		$e = GDW_Setup::unlink(
			$repo,
			[
				'delete_key' => ! empty( $_POST['delete_key'] ),
				'remove_git' => ! empty( $_POST['remove_git'] ),
			]
		);
		$e['ok']
			? self::done( 'success', "Unlinked {$repo['id']}.", $e['output'] )
			: self::done( 'error', "Could not unlink {$repo['id']}.", $e['output'], [ 'view' => 'unlink', 'repo' => $repo['id'] ] );
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
			[ 'view' => 'edit', 'repo' => $repo['id'], 'step' => 'deploy' ]
		);
	}

	public static function handle_test() {
		self::guard( 'test' );
		$repo         = self::posted_repo();
		[ $ok, $msg ] = GDW_Deployer::test( $repo );
		if ( function_exists( 'proc_open' ) ) {
			GDW_Setup::inspect( $repo ); // Refreshes step 2's status and step 3's comparison.
		}
		self::done(
			$ok ? 'success' : 'error',
			$ok ? $msg . ' Next, set up the folder.' : 'Connection failed.',
			$ok ? '' : $msg,
			[ 'view' => 'edit', 'repo' => $repo['id'], 'step' => $ok ? 'setup' : 'key' ]
		);
	}

	public static function handle_keygen() {
		self::guard( 'keygen' );
		$repo         = self::posted_repo();
		[ $ok, $msg ] = GDW_Deployer::generate_key( $repo );
		self::done(
			$ok ? 'success' : 'error',
			$ok ? 'Deploy key created. Add it to GitHub below.' : 'Could not create a deploy key.',
			$ok ? '' : $msg,
			[ 'view' => 'edit', 'repo' => $repo['id'], 'step' => 'key' ]
		);
	}

	public static function handle_settings() {
		self::guard( 'settings' );
		$back     = [ 'tab' => 'settings' ];
		$settings = get_option( GDW_Config::SETTINGS, [] );
		$settings = is_array( $settings ) ? $settings : [];
		foreach ( [ 'key_dir' => 'GDW_KEY_DIR', 'backup_dir' => 'GDW_BACKUP_DIR' ] as $field => $const ) {
			if ( defined( $const ) || ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$dir = GDW_Config::sanitize_abs_path( wp_unslash( $_POST[ $field ] ) );
			if ( '' === $dir || false !== strpos( $dir, '..' ) ) {
				self::done( 'error', 'Please enter absolute folder paths.', '', $back );
			}
			$settings[ $field ] = rtrim( $dir, '/' );
		}
		update_option( GDW_Config::SETTINGS, $settings, false );
		$problems = GDW_Deployer::ensure_dirs();
		$problems
			? self::done( 'warning', 'Settings saved, but a folder could not be prepared.', implode( "\n", $problems ), $back )
			: self::done( 'success', 'Settings saved. Both folders are ready.', '', $back );
	}

	public static function handle_inspect() {
		self::guard( 'inspect' );
		$repo = self::posted_repo();
		GDW_Setup::inspect( $repo );
		wp_safe_redirect( self::step_url( $repo['id'], 'setup' ) );
		exit;
	}

	public static function handle_use_repo() {
		self::guard( 'use_repo' );
		$repo = self::posted_repo();
		$e    = GDW_Setup::use_repo( $repo, ! empty( $_POST['backup'] ) );
		GDW_Setup::inspect( $repo );
		self::done(
			$e['ok'] ? 'success' : 'error',
			$e['ok'] ? "The folder now matches {$repo['branch']} on GitHub. Next, turn on automatic deploys." : 'Setup failed.',
			$e['output'],
			[ 'view' => 'edit', 'repo' => $repo['id'], 'step' => $e['ok'] ? 'auto' : 'setup' ]
		);
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
		self::done( $e['ok'] ? 'success' : 'error', $e['ok'] ? "Pushed: {$e['head']}" : 'Push failed.', $e['output'], [ 'view' => 'edit', 'repo' => $repo['id'], 'step' => 'setup' ] );
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

	private static function done( $type, $msg, $detail = '', array $args = [] ) {
		set_transient( 'gdw_notice_' . get_current_user_id(), compact( 'type', 'msg', 'detail' ), 120 );
		wp_safe_redirect( self::url( $args ) );
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
}
