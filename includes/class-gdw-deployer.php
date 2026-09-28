<?php
/**
 * The git pipeline and supporting server operations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GDW_Deployer {

	const LOG_KEEP   = 20;
	const LOG_MAX    = 20000; // bytes of output kept per entry
	const TIMEOUT    = 300;   // seconds per command

	/**
	 * init (first run) → set remote → fetch → checkout -f -B → clean → optional .deploy/deploy.sh
	 *
	 * Without $force it refuses to touch a folder that has files but was never set up
	 * (see GDW_Setup), and respects "protect local changes". Setup passes $force.
	 *
	 * @return array{time:int,trigger:string,ok:bool,head:string,output:string}
	 */
	public static function run( array $repo, $trigger, $force = false, $preface = '' ) {
		$dir = GDW_Config::abs_path( $repo );

		if ( '' === $dir ) {
			return self::record( $repo, $trigger, false, '', 'No valid folder configured.' );
		}
		if ( ! function_exists( 'proc_open' ) ) {
			return self::record( $repo, $trigger, false, '', 'proc_open() is disabled in PHP. Enable it, or use queue mode with the WP-CLI cron.' );
		}
		if ( ! self::lock( $repo['id'] ) ) {
			return self::record( $repo, $trigger, false, '', 'Skipped: another deploy of this repository is running.' );
		}

		@set_time_limit( self::TIMEOUT + 60 );

		$out  = '' !== $preface ? [ rtrim( $preface ) ] : [];
		$git  = [ 'git', '-c', 'safe.directory=' . $dir ];
		$step = function ( array $args ) use ( &$out, $git, $dir, $repo ) {
			[ $code, $text ] = self::exec( array_merge( $git, $args ), $dir, $repo );
			$out[]           = '$ git ' . implode( ' ', $args ) . ( '' !== trim( $text ) ? "\n" . trim( $text ) : '' );
			return 0 === $code;
		};

		$ok = ( function () use ( $step, &$out, $git, $dir, $repo, $force ) {
			$has_head = is_dir( $dir . '/.git' )
				&& 0 === self::exec( array_merge( $git, [ 'rev-parse', '--verify', '-q', 'HEAD' ] ), $dir, $repo, 30 )[0];

			// Never replace an existing, non-git folder (e.g. a live theme) from a webhook.
			if ( ! $force && ! $has_head && GDW_Setup::has_files( $dir ) ) {
				$out[] = 'This folder has files but has not been set up yet, so nothing was changed. '
					. 'Open Tools → Git Deploy and use Setup to choose whether GitHub or this folder wins.';
				return false;
			}

			if ( $has_head ) {
				[ $code, $changes ] = self::exec( array_merge( $git, [ 'status', '--porcelain' ] ), $dir, $repo, 60 );
				$changes            = trim( $changes );
				if ( 0 === $code && '' !== $changes ) {
					if ( $repo['protect_changes'] && ! $force ) {
						$out[] = "Skipped: the folder has uncommitted changes and 'Protect local changes' is on.\n"
							. 'Publish or discard them in Tools → Git Deploy → Setup.' . "\n" . self::cap( $changes );
						return false;
					}
					$out[] = "Discarding local changes:\n" . self::cap( $changes );
				}
			}

			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				$out[] = "Cannot create {$dir}. Check that " . self::whoami() . ' can write to its parent folder.';
				return false;
			}
			if ( ! is_writable( $dir ) ) {
				$out[] = "{$dir} is not writable by " . self::whoami() . '.';
				return false;
			}
			if ( ! is_dir( $dir . '/.git' ) && ! $step( [ 'init', '-q' ] ) ) {
				return false;
			}
			if ( '' !== $repo['repo_url'] ) {
				[ $has_origin ] = self::exec( array_merge( $git, [ 'remote', 'get-url', 'origin' ] ), $dir, $repo );
				$args           = 0 === $has_origin
					? [ 'remote', 'set-url', 'origin', $repo['repo_url'] ]
					: [ 'remote', 'add', 'origin', $repo['repo_url'] ];
				if ( ! $step( $args ) ) {
					return false;
				}
			}
			$b = $repo['branch'];
			return $step( [ 'fetch', '--prune', 'origin', "+refs/heads/{$b}:refs/remotes/origin/{$b}" ] )
				&& $step( [ 'checkout', '-q', '-f', '-B', $b, "origin/{$b}" ] )
				&& $step( [ 'clean', '-fdq' ] );
		} )();

		// Checked after checkout, so a new or changed hook in the repo is used immediately.
		$hook = $dir . '/.deploy/deploy.sh';
		if ( $ok && $repo['run_hook'] && is_file( $hook ) ) {
			[ $code, $text ] = self::exec( [ 'bash', $hook ], $dir, $repo );
			$out[]           = '$ bash .deploy/deploy.sh' . ( '' !== trim( $text ) ? "\n" . trim( $text ) : '' );
			$ok              = 0 === $code;
		}

		$head = '';
		if ( is_dir( $dir . '/.git' ) ) {
			[ , $h ] = self::exec( array_merge( $git, [ 'log', '-1', '--format=%h %s' ] ), $dir, $repo );
			$head    = substr( trim( $h ), 0, 120 );
		}

		// Only affects the web server's opcache when run inside PHP-FPM/mod_php.
		if ( $ok && PHP_SAPI !== 'cli' && function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}

		self::unlock( $repo['id'] );

		return self::record( $repo, $trigger, $ok, $head, implode( "\n", $out ) );
	}

	/* ---------------------------------------------------------------- */
	/* Process execution                                                 */
	/* ---------------------------------------------------------------- */

	/** @return array{0:int,1:string} exit code, combined stdout+stderr */
	public static function exec( array $cmd, $cwd, array $repo, $timeout = self::TIMEOUT ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return [ 127, 'proc_open() is disabled.' ];
		}

		$proc = @proc_open(
			$cmd,
			[
				0 => [ 'file', '/dev/null', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'redirect', 1 ],
			],
			$pipes,
			$cwd,
			self::env( $repo )
		);

		if ( ! is_resource( $proc ) ) {
			return [ 127, 'Could not start ' . $cmd[0] . ' (is it installed and in PATH?)' ];
		}

		stream_set_blocking( $pipes[1], false );
		$text   = '';
		$start  = time();
		$killed = false;

		while ( true ) {
			$read   = [ $pipes[1] ];
			$write  = null;
			$except = null;
			if ( false === @stream_select( $read, $write, $except, 1 ) ) {
				break;
			}
			$chunk = fread( $pipes[1], 8192 );
			if ( false !== $chunk && '' !== $chunk ) {
				$text .= $chunk;
				continue;
			}
			if ( feof( $pipes[1] ) ) {
				break;
			}
			if ( time() - $start > $timeout ) {
				proc_terminate( $proc, 9 );
				$text  .= "\n[killed after {$timeout}s]";
				$killed = true;
				break;
			}
		}

		fclose( $pipes[1] );
		$code = proc_close( $proc );

		return [ $killed ? 124 : (int) $code, $text ];
	}

	/** Run git inside the repository folder. @return array{0:int,1:string} */
	public static function git( array $repo, array $args, $timeout = 60 ) {
		$dir = GDW_Config::abs_path( $repo );
		return self::exec( array_merge( [ 'git', '-c', 'safe.directory=' . $dir ], $args ), $dir, $repo, $timeout );
	}

	/** First lines of long git output for logs. */
	public static function cap( $text, $lines = 30 ) {
		$all = explode( "\n", trim( $text ) );
		return implode( "\n", array_slice( $all, 0, $lines ) ) . ( count( $all ) > $lines ? "\n… and " . ( count( $all ) - $lines ) . ' more' : '' );
	}

	private static function env( array $repo ) {
		$dir  = GDW_Config::abs_path( $repo );
		$key  = GDW_Config::ssh_key( $repo );
		$home = getenv( 'HOME' );

		$env = [
			'PATH'                => getenv( 'PATH' ) ?: '/usr/local/bin:/usr/bin:/bin',
			'GIT_TERMINAL_PROMPT' => '0',
			'GDW_REPO_ID'         => $repo['id'],
			'GDW_REPO_DIR'        => $dir,
			'WP_ROOT'             => rtrim( ABSPATH, '/' ),
		];

		// Use the deploy key if this process can read it; otherwise fall back to the
		// running user's own ~/.ssh setup (e.g. a deploy user running WP-CLI).
		if ( $key && is_readable( $key ) ) {
			$kdir                   = dirname( $key );
			$env['GIT_SSH_COMMAND'] = sprintf(
				'ssh -i %s -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=%s',
				escapeshellarg( $key ),
				escapeshellarg( $kdir . '/known_hosts' )
			);
			$env['HOME']            = $home ?: $kdir;
		} else {
			$env['HOME'] = $home ?: sys_get_temp_dir();
		}

		return $env;
	}

	/* ---------------------------------------------------------------- */
	/* Locking (MySQL named lock: works across PHP-FPM and WP-CLI users)  */
	/* ---------------------------------------------------------------- */

	private static function lock_name( $id ) {
		return 'gdw_' . substr( md5( ABSPATH . '|' . $id ), 0, 24 );
	}

	public static function lock( $id ) {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name( $id ) ) );
	}

	public static function unlock( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $id ) ) );
	}

	/* ---------------------------------------------------------------- */
	/* Log + queue (stored in options, so no shared files are needed)    */
	/* ---------------------------------------------------------------- */

	public static function record( array $repo, $trigger, $ok, $head, $output ) {
		$entry = [
			'time'    => time(),
			'trigger' => (string) $trigger,
			'ok'      => (bool) $ok,
			'head'    => (string) $head,
			'output'  => substr( (string) $output, 0, self::LOG_MAX ),
		];

		if ( '' !== $repo['id'] ) {
			$log = self::log( $repo['id'] );
			array_unshift( $log, $entry );
			update_option( 'gdw_log_' . $repo['id'], array_slice( $log, 0, self::LOG_KEEP ), false );
		}

		do_action( 'gdw_after_deploy', $repo['id'], $entry['ok'], $trigger, $head, $entry['output'] );

		return $entry;
	}

	public static function log( $id ) {
		$log = get_option( 'gdw_log_' . $id, [] );
		return is_array( $log ) ? $log : [];
	}

	public static function queue( $id, $sha ) {
		update_option( 'gdw_pending_' . $id, [ 'sha' => (string) $sha, 'time' => time() ], false );
	}

	public static function pending( $id ) {
		$p = get_option( 'gdw_pending_' . $id );
		return is_array( $p ) ? $p : null;
	}

	/** Clear the pending flag; true if there was one. */
	public static function take_pending( $id ) {
		if ( ! self::pending( $id ) ) {
			return false;
		}
		delete_option( 'gdw_pending_' . $id );
		return true;
	}

	/* ---------------------------------------------------------------- */
	/* Admin helpers                                                     */
	/* ---------------------------------------------------------------- */

	/** Human-readable state of the working copy. */
	public static function status( array $repo ) {
		$dir = GDW_Config::abs_path( $repo );
		if ( '' === $dir || ! is_dir( $dir . '/.git' ) ) {
			return 'Not a git repository yet. The first deploy will initialise it.';
		}
		[ $code, $text ] = self::exec(
			[ 'git', '-c', 'safe.directory=' . $dir, 'log', '-1', '--format=%h %s (%cr)' ],
			$dir,
			$repo,
			20
		);
		[ , $branch ] = self::exec( [ 'git', '-c', 'safe.directory=' . $dir, 'rev-parse', '--abbrev-ref', 'HEAD' ], $dir, $repo, 20 );
		return 0 === $code ? trim( $branch ) . ' @ ' . trim( $text ) : trim( $text );
	}

	/** Check that the server can reach the branch. @return array{0:bool,1:string} */
	public static function test( array $repo ) {
		$dir = GDW_Config::abs_path( $repo );
		$ref = 'refs/heads/' . $repo['branch'];

		if ( '' !== $repo['repo_url'] ) {
			[ $code, $text ] = self::exec( [ 'git', 'ls-remote', '--heads', $repo['repo_url'], $ref ], sys_get_temp_dir(), $repo, 60 );
		} elseif ( '' !== $dir && is_dir( $dir . '/.git' ) ) {
			[ $code, $text ] = self::exec( [ 'git', '-c', 'safe.directory=' . $dir, 'ls-remote', '--heads', 'origin', $ref ], $dir, $repo, 60 );
		} else {
			return [ false, 'Set a repository URL first.' ];
		}

		if ( 0 !== $code ) {
			return [ false, trim( $text ) ];
		}
		if ( ! preg_match( '#^([0-9a-f]{40})\s+' . preg_quote( $ref, '#' ) . '$#m', $text, $m ) ) {
			return [ false, "Connected, but branch '{$repo['branch']}' was not found." ];
		}
		return [ true, "Connected. {$repo['branch']} is at " . substr( $m[1], 0, 10 ) . '.' ];
	}

	/** @return array{0:bool,1:string} */
	public static function generate_key( array $repo ) {
		$dir = GDW_Config::key_dir();
		$who = self::whoami();

		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) ) {
			return [ false, "Could not create {$dir}. Run: sudo mkdir -p {$dir} && sudo chown {$who} {$dir} && sudo chmod 700 {$dir}" ];
		}
		if ( ! is_writable( $dir ) ) {
			return [ false, "{$dir} is not writable by {$who}. Run: sudo chown {$who} {$dir} && sudo chmod 700 {$dir}" ];
		}

		$path = $dir . '/' . $repo['id'];
		if ( file_exists( $path ) ) {
			return [ false, "A key already exists at {$path}." ];
		}

		$host            = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'wordpress';
		[ $code, $text ] = self::exec(
			[ 'ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', "gdw-{$repo['id']}@{$host}", '-f', $path ],
			$dir,
			$repo,
			30
		);
		@chmod( $path, 0600 );

		return 0 === $code ? [ true, "Key created at {$path}." ] : [ false, trim( $text ) ];
	}

	public static function public_key( array $repo ) {
		$key = GDW_Config::ssh_key( $repo );
		return ( $key && is_readable( $key . '.pub' ) ) ? trim( (string) file_get_contents( $key . '.pub' ) ) : '';
	}

	public static function whoami() {
		if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
			$u = posix_getpwuid( posix_geteuid() );
			if ( ! empty( $u['name'] ) ) {
				return $u['name'];
			}
		}
		[ $code, $text ] = self::exec( [ 'id', '-un' ], sys_get_temp_dir(), GDW_Config::normalize( '', [] ), 10 );
		return 0 === $code ? trim( $text ) : 'the PHP user';
	}

	/** @return array<int,array{0:string,1:bool,2:string}> label, ok, info */
	public static function checks() {
		$who    = self::whoami();
		$proc   = function_exists( 'proc_open' );
		$checks = [
			[ 'PHP runs as', true, "{$who}. In direct mode this user must be able to write the repository folders." ],
			[ 'proc_open()', $proc, $proc ? 'Available.' : 'Disabled (disable_functions in php.ini). Direct mode and the buttons on this page need it. Otherwise use queue mode with the WP-CLI cron.' ],
		];

		if ( $proc ) {
			[ $code, $text ] = self::exec( [ 'git', '--version' ], sys_get_temp_dir(), GDW_Config::normalize( '', [] ), 10 );
			$checks[]        = [ 'git', 0 === $code, 0 === $code ? trim( $text ) : 'git was not found in PATH for ' . $who . '.' ];
		}

		$fcgi     = function_exists( 'fastcgi_finish_request' );
		$checks[] = [ 'fastcgi_finish_request()', $fcgi, $fcgi ? 'Webhooks reply to GitHub before git runs.' : 'Not available (mod_php?). Deploys still run, but GitHub may report a timeout on slow pulls.' ];

		$kd       = GDW_Config::key_dir();
		$kd_ok    = is_dir( $kd ) && is_writable( $kd );
		$checks[] = [ 'Deploy key folder', $kd_ok, $kd_ok ? $kd : "{$kd} does not exist or is not writable by {$who}. Create it with: sudo mkdir -p {$kd} && sudo chown {$who} {$kd} && sudo chmod 700 {$kd} (keep it outside the web root)." ];

		$bd       = GDW_Config::backup_dir();
		$bd_ok    = is_dir( $bd ) && is_writable( $bd );
		$checks[] = [ 'Backup folder', $bd_ok, $bd_ok ? $bd : "{$bd} does not exist or is not writable by {$who}. Setup backs up folders here before replacing them. Create it with: sudo mkdir -p {$bd} && sudo chown {$who} {$bd} && sudo chmod 700 {$bd}" ];

		return $checks;
	}
}
