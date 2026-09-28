<?php
/**
 * Repository configuration.
 *
 * Repos are stored in the `gdw_repos` option (edited in Tools → Git Deploy).
 * Any repo can instead be defined in wp-config.php, which wins and locks it in the UI:
 *
 *   define( 'GDW_REPOS', [
 *       'site1-theme' => [
 *           'path'     => 'themes/site1-theme',                 // relative to wp-content
 *           'repo_url' => 'git@github.com:org/site1-theme.git',
 *           'branch'   => 'main',
 *           'mode'     => 'direct',                             // or 'queue'
 *           'secret'   => 'long-random-string',
 *       ],
 *   ] );
 *
 *   define( 'GDW_KEY_DIR', '/var/www/.git-deploy-keys' );        // optional
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GDW_Config {

	const OPTION   = 'gdw_repos';
	const SETTINGS = 'gdw_settings';

	public static function defaults() {
		return [
			'id'       => '',
			'path'     => '',
			'repo_url' => '',
			'branch'   => 'main',
			'mode'     => 'direct',
			'ssh_key'  => '',
			'secret'   => '',
			'enabled'  => true,
			'run_hook' => true,
			'protect_changes' => false,
			'locked'   => false,
		];
	}

	/** @return array<string,array> */
	public static function repos() {
		$stored = get_option( self::OPTION, [] );
		$repos  = [];

		foreach ( is_array( $stored ) ? $stored : [] as $id => $r ) {
			$id = self::sanitize_id( $id );
			if ( $id ) {
				$repos[ $id ]           = self::normalize( $id, (array) $r );
				$repos[ $id ]['locked'] = false;
			}
		}

		if ( defined( 'GDW_REPOS' ) && is_array( GDW_REPOS ) ) {
			foreach ( GDW_REPOS as $id => $r ) {
				$id = self::sanitize_id( $id );
				if ( $id ) {
					$repos[ $id ]           = self::normalize( $id, array_merge( $repos[ $id ] ?? [], (array) $r ) );
					$repos[ $id ]['locked'] = true;
				}
			}
		}

		ksort( $repos );
		return $repos;
	}

	public static function get( $id ) {
		$repos = self::repos();
		return $repos[ $id ] ?? null;
	}

	public static function normalize( $id, array $r ) {
		$r = array_merge( self::defaults(), array_intersect_key( $r, self::defaults() ) );

		$r['id']       = (string) $id;
		$r['path']     = self::sanitize_path( $r['path'] );
		$r['repo_url'] = self::sanitize_repo_url( $r['repo_url'] );
		$r['branch']   = self::sanitize_branch( $r['branch'] );
		$r['mode']     = in_array( $r['mode'], [ 'direct', 'queue' ], true ) ? $r['mode'] : 'direct';
		$r['ssh_key']  = self::sanitize_abs_path( $r['ssh_key'] );
		$r['secret']   = trim( (string) $r['secret'] );
		$r['enabled']  = (bool) $r['enabled'];
		$r['run_hook'] = (bool) $r['run_hook'];
		$r['protect_changes'] = (bool) $r['protect_changes'];

		return $r;
	}

	public static function save_repo( array $repo ) {
		$all = get_option( self::OPTION, [] );
		$all = is_array( $all ) ? $all : [];
		$id  = $repo['id'];
		unset( $repo['id'], $repo['locked'] );
		$all[ $id ] = $repo;
		update_option( self::OPTION, $all, false );
	}

	public static function delete_repo( $id ) {
		$all = get_option( self::OPTION, [] );
		if ( is_array( $all ) && isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			update_option( self::OPTION, $all, false );
		}
		delete_option( 'gdw_log_' . $id );
		delete_option( 'gdw_pending_' . $id );
	}

	/* ---------------------------------------------------------------- */

	public static function sanitize_id( $id ) {
		$id = strtolower( preg_replace( '/[^a-z0-9_-]/i', '', is_scalar( $id ) ? (string) $id : '' ) );
		return substr( $id, 0, 64 );
	}

	/**
	 * Relative path inside wp-content; '' if invalid. Must be at least two levels deep
	 * (themes/x, plugins/x, …) so a whole themes/ or uploads/ folder can never be mirrored.
	 */
	public static function sanitize_path( $path ) {
		$path  = str_replace( '\\', '/', (string) $path );
		$parts = array_filter(
			explode( '/', $path ),
			function ( $s ) {
				return '' !== trim( $s ) && '.' !== $s;
			}
		);
		foreach ( $parts as $s ) {
			if ( '..' === $s || ! preg_match( '/^[A-Za-z0-9._-]+$/', $s ) ) {
				return '';
			}
		}
		$parts = array_values( $parts );
		if ( count( $parts ) < 2 && [ 'mu-plugins' ] !== $parts ) {
			return '';
		}
		return implode( '/', $parts );
	}

	public static function sanitize_branch( $branch ) {
		$branch = trim( (string) $branch );
		$valid  = preg_match( '#^[A-Za-z0-9._/-]+$#', $branch )
			&& false === strpos( $branch, '..' )
			&& '-' !== $branch[0];
		return $valid ? $branch : 'main';
	}

	/** Accepts git@host:org/repo.git, ssh://… or https://…; '' if invalid. */
	public static function sanitize_repo_url( $url ) {
		$url = trim( (string) $url );
		if ( preg_match( '#^git@[A-Za-z0-9.-]+:[A-Za-z0-9._/-]+$#', $url ) ) {
			return $url;
		}
		if ( preg_match( '#^(ssh|https)://[^\s\'"]+$#', $url ) ) {
			return $url;
		}
		return '';
	}

	public static function sanitize_abs_path( $path ) {
		$path = trim( (string) $path );
		return ( '' !== $path && '/' === $path[0] && ! preg_match( '/[\r\n\0]/', $path ) ) ? $path : '';
	}

	/* ---------------------------------------------------------------- */

	/** Absolute folder for a repo, '' if no valid path. */
	public static function abs_path( array $repo ) {
		return '' === $repo['path'] ? '' : WP_CONTENT_DIR . '/' . $repo['path'];
	}

	public static function key_dir() {
		if ( defined( 'GDW_KEY_DIR' ) ) {
			return rtrim( GDW_KEY_DIR, '/' );
		}
		$settings = get_option( self::SETTINGS, [] );
		$dir      = is_array( $settings ) ? ( $settings['key_dir'] ?? '' ) : '';
		return rtrim( $dir ?: dirname( rtrim( ABSPATH, '/' ) ) . '/.git-deploy-keys', '/' );
	}

	public static function backup_dir() {
		if ( defined( 'GDW_BACKUP_DIR' ) ) {
			return rtrim( GDW_BACKUP_DIR, '/' );
		}
		$settings = get_option( self::SETTINGS, [] );
		$dir      = is_array( $settings ) ? ( $settings['backup_dir'] ?? '' ) : '';
		return rtrim( $dir ?: dirname( rtrim( ABSPATH, '/' ) ) . '/.git-deploy-backups', '/' );
	}

	/** Which repo (if any) already manages this path. */
	public static function repo_for_path( $path, $except_id = '' ) {
		foreach ( self::repos() as $r ) {
			if ( $r['path'] === $path && $r['id'] !== $except_id ) {
				return $r['id'];
			}
		}
		return '';
	}

	/** Effective private key: explicit path, else a generated key in the key dir, else ''. */
	public static function ssh_key( array $repo ) {
		if ( '' !== $repo['ssh_key'] ) {
			return $repo['ssh_key'];
		}
		if ( '' === $repo['id'] ) {
			return '';
		}
		$key = self::key_dir() . '/' . $repo['id'];
		return is_file( $key ) ? $key : '';
	}

	public static function webhook_url( $id ) {
		return rest_url( 'git-deploy/v1/' . $id );
	}
}
