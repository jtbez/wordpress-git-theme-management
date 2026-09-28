<?php
/**
 * Update notices for this plugin from GitHub Releases.
 *
 * The plugin header "Update URI: https://github.com/<owner>/<repo>" (WordPress 5.8+)
 * tells WordPress not to ask WordPress.org about this plugin and to call the
 * update_plugins_github.com filter instead. We answer it with the latest
 * published (non-draft, non-prerelease) GitHub Release, which gives the normal
 * update notice, "View details" modal, one-click update and auto-updates.
 *
 * Release tags must be versions: v1.2.0 or 1.2.0. The package is the release
 * asset named <plugin-folder>.zip (built by .github/workflows/release.yml),
 * falling back to any .zip asset, then GitHub's source zipball.
 *
 * Optional lines in the release notes are picked up and shown to WordPress:
 *   Requires at least: 5.8
 *   Requires PHP: 7.4
 *   Tested up to: 6.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GDW_Updater {

	const CACHE       = 'gdw_github_release';
	const CACHE_TTL   = 21600; // 6 hours
	const CACHE_ERROR = 3600;  // retry failed lookups after 1 hour

	/** @var string|null */
	private static $repo = null;

	public static function init() {
		// A copy of this plugin that is itself a git checkout (e.g. deployed by Git Deploy)
		// must not be overwritten by a zip update, which would discard its .git folder.
		if ( is_dir( dirname( GDW_FILE ) . '/.git' ) || ( defined( 'GDW_DISABLE_UPDATER' ) && GDW_DISABLE_UPDATER ) ) {
			return;
		}

		add_filter( 'update_plugins_github.com', [ __CLASS__, 'check' ], 10, 3 );
		add_filter( 'plugins_api', [ __CLASS__, 'details' ], 20, 3 );
		add_filter( 'upgrader_source_selection', [ __CLASS__, 'fix_folder' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'flush' ], 10, 0 );
		add_action( 'load-update-core.php', [ __CLASS__, 'maybe_flush' ] );
	}

	/** "owner/repo" parsed from the Update URI header, or ''. */
	public static function repo() {
		if ( null === self::$repo ) {
			$headers    = get_file_data( GDW_FILE, [ 'uri' => 'Update URI' ] );
			self::$repo = preg_match( '#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?/?$#', trim( (string) $headers['uri'] ), $m )
				? $m[1]
				: '';
		}
		return self::$repo;
	}

	private static function basename() {
		return plugin_basename( GDW_FILE );
	}

	private static function slug() {
		return dirname( self::basename() );
	}

	/* ---------------------------------------------------------------- */
	/* WordPress hooks                                                   */
	/* ---------------------------------------------------------------- */

	/** update_plugins_github.com: WordPress compares 'version' with the installed one itself. */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( $plugin_file !== self::basename() || ! self::repo() ) {
			return $update;
		}
		$r = self::release();
		if ( ! $r ) {
			return $update;
		}
		return array_filter(
			[
				'slug'         => self::slug(),
				'version'      => $r['version'],
				'url'          => $r['url'],
				'package'      => $r['package'],
				'requires'     => $r['requires'],
				'requires_php' => $r['requires_php'],
				'tested'       => $r['tested'],
			]
		);
	}

	/** The "View version x.y.z details" modal. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== self::slug() || ! self::repo() ) {
			return $result;
		}
		$r = self::release();
		if ( ! $r ) {
			return $result;
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin = get_plugin_data( GDW_FILE, false, false );

		return (object) [
			'name'          => $plugin['Name'],
			'slug'          => self::slug(),
			'version'       => $r['version'],
			'author'        => $plugin['Author'],
			'homepage'      => 'https://github.com/' . self::repo(),
			'download_link' => $r['package'],
			'last_updated'  => $r['published'],
			'requires'      => $r['requires'],
			'requires_php'  => $r['requires_php'],
			'tested'        => $r['tested'],
			'sections'      => [
				'description' => '<p>' . esc_html( $plugin['Description'] ) . '</p>',
				'changelog'   => '<h4>' . esc_html( $r['version'] ) . '</h4>'
					. wpautop( make_clickable( esc_html( $r['body'] ) ) )
					. '<p><a href="' . esc_url( $r['url'] ) . '" target="_blank" rel="noopener">View this release on GitHub</a></p>',
			],
		];
	}

	/**
	 * GitHub source zipballs unpack to "owner-repo-<sha>/". Rename to the installed
	 * folder name so the update replaces the plugin instead of installing a copy.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		if ( ! is_array( $hook_extra ) || ( $hook_extra['plugin'] ?? '' ) !== self::basename() ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . self::slug();
		if ( untrailingslashit( $source ) === $want ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), $want, true ) ) {
			return trailingslashit( $want );
		}
		return new WP_Error( 'gdw_update_folder', 'Could not rename the downloaded plugin folder.' );
	}

	public static function flush() {
		delete_site_transient( self::CACHE );
	}

	/** Dashboard → Updates → "Check again" also re-checks GitHub. */
	public static function maybe_flush() {
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::flush();
		}
	}

	/* ---------------------------------------------------------------- */
	/* GitHub API                                                        */
	/* ---------------------------------------------------------------- */

	/** @return array|null Cached latest release. */
	private static function release() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached ?: null;
		}

		$res  = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'wordpress-git-theme-management/' . GDW_VERSION,
				],
			]
		);
		$data = [];
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$data = self::parse( json_decode( wp_remote_retrieve_body( $res ), true ) );
		}

		// Cache failures too (as an empty array), so an API outage or the
		// 60 requests/hour unauthenticated limit doesn't slow every admin page.
		set_site_transient( self::CACHE, $data, $data ? self::CACHE_TTL : self::CACHE_ERROR );
		return $data ?: null;
	}

	/** Turn a GitHub release object into what WordPress needs; [] if unusable. */
	public static function parse( $r ) {
		if ( ! is_array( $r ) || ! empty( $r['draft'] ) || ! empty( $r['prerelease'] ) ) {
			return [];
		}

		$version = ltrim( (string) ( $r['tag_name'] ?? '' ), 'vV' );
		if ( ! preg_match( '/^\d+(\.\d+){0,3}$/', $version ) ) {
			return [];
		}

		$zips = [];
		foreach ( (array) ( $r['assets'] ?? [] ) as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			if ( '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
				$zips[ $name ] = (string) $asset['browser_download_url'];
			}
		}
		$package = $zips[ self::slug() . '.zip' ] ?? ( reset( $zips ) ?: (string) ( $r['zipball_url'] ?? '' ) );
		if ( '' === $package ) {
			return [];
		}

		$body = (string) ( $r['body'] ?? '' );
		$meta = function ( $label ) use ( $body ) {
			return preg_match( '/^\s*' . preg_quote( $label, '/' ) . ':\s*([\d.]+)/mi', $body, $m ) ? $m[1] : '';
		};

		return [
			'version'      => $version,
			'package'      => esc_url_raw( $package ),
			'url'          => esc_url_raw( (string) ( $r['html_url'] ?? 'https://github.com/' . self::repo() . '/releases' ) ),
			'body'         => $body,
			'published'    => (string) ( $r['published_at'] ?? '' ),
			'requires'     => $meta( 'Requires at least' ),
			'requires_php' => $meta( 'Requires PHP' ),
			'tested'       => $meta( 'Tested up to' ),
		];
	}
}
