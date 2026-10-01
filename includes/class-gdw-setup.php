<?php
/**
 * First-time setup (and fixing a folder that drifted from GitHub).
 *
 * inspect()  looks at both sides: the folder (files? .git? which origin? local changes?)
 *            and GitHub (reachable? branch exists?), then compares their histories.
 * use_repo() makes the folder match GitHub (backing the folder up first).
 * publish()  commits the folder and pushes it to GitHub, onto the configured branch
 *            or a new branch you can merge with a pull request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GDW_Setup {

	const GITIGNORE = "# Added by Git Deploy\n.DS_Store\nThumbs.db\nnode_modules/\n*.log\n.env\n";

	/* ---------------------------------------------------------------- */
	/* Helpers                                                           */
	/* ---------------------------------------------------------------- */

	/** True if $dir has anything besides a .git folder. */
	public static function has_files( $dir ) {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return false;
		}
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( ! in_array( $f, [ '.', '..', '.git' ], true ) ) {
				return true;
			}
		}
		return false;
	}

	/** The origin URL of an existing checkout at an absolute path, or ''. */
	public static function origin_of( $dir ) {
		if ( ! is_dir( $dir . '/.git' ) ) {
			return '';
		}
		[ $code, $text ] = GDW_Deployer::exec(
			[ 'git', '-c', 'safe.directory=' . $dir, 'remote', 'get-url', 'origin' ],
			$dir,
			GDW_Config::normalize( '', [] ),
			20
		);
		return 0 === $code ? trim( $text ) : '';
	}

	private static function val( array $repo, array $args, $timeout = 60 ) {
		[ $code, $text ] = GDW_Deployer::git( $repo, $args, $timeout );
		return 0 === $code ? trim( $text ) : '';
	}

	public static function cached( $id ) {
		$s = get_transient( 'gdw_inspect_' . $id );
		return is_array( $s ) ? $s : null;
	}

	public static function forget( $id ) {
		delete_transient( 'gdw_inspect_' . $id );
	}

	/* ---------------------------------------------------------------- */
	/* Inspect                                                           */
	/* ---------------------------------------------------------------- */

	public static function inspect( array $repo ) {
		$dir = GDW_Config::abs_path( $repo );
		$b   = $repo['branch'];
		$s   = [
			'time'            => time(),
			'exists'          => '' !== $dir && is_dir( $dir ),
			'empty'           => true,
			'active'          => in_array( $repo['path'], [ 'themes/' . get_stylesheet(), 'themes/' . get_template() ], true ),
			'git'             => false,
			'head'            => '',
			'local_branch'    => '',
			'origin'          => '',
			'dirty'           => [],
			'dirty_count'     => 0,
			'remote_ok'       => false,
			'remote_error'    => '',
			'remote_sha'      => '',
			'remote_branches' => [],
			'relation'        => 'none',
			'ahead'           => 0,
			'behind'          => 0,
		];

		if ( $s['exists'] ) {
			$s['empty'] = ! self::has_files( $dir );
			if ( is_dir( $dir . '/.git' ) ) {
				$s['git']          = true;
				$s['head']         = self::val( $repo, [ 'rev-parse', '--verify', '-q', 'HEAD' ] );
				$s['local_branch'] = self::val( $repo, [ 'symbolic-ref', '--short', '-q', 'HEAD' ] );
				$s['origin']       = self::val( $repo, [ 'remote', 'get-url', 'origin' ] );
				[ $code, $text ]   = GDW_Deployer::git( $repo, [ 'status', '--porcelain' ] );
				$lines             = 0 === $code ? array_values( array_filter( explode( "\n", rtrim( $text ) ) ) ) : [];
				$s['dirty_count']  = count( $lines );
				$s['dirty']        = array_slice( $lines, 0, 50 );
			}
		}

		$url = '' !== $repo['repo_url'] ? $repo['repo_url'] : $s['origin'];
		if ( '' === $url ) {
			$s['remote_error'] = 'No repository URL is set.';
		} else {
			[ $code, $text ] = GDW_Deployer::exec( [ 'git', 'ls-remote', '--heads', $url ], sys_get_temp_dir(), $repo, 60 );
			if ( 0 !== $code ) {
				$s['remote_error'] = trim( $text );
			} else {
				$s['remote_ok'] = true;
				preg_match_all( '#^([0-9a-f]{40})\s+refs/heads/(\S+)$#m', $text, $m, PREG_SET_ORDER );
				foreach ( $m as $row ) {
					$s['remote_branches'][ $row[2] ] = $row[1];
				}
				$s['remote_sha'] = $s['remote_branches'][ $b ] ?? '';
			}
		}

		if ( '' !== $s['head'] && '' !== $s['remote_sha'] ) {
			if ( $s['head'] === $s['remote_sha'] ) {
				$s['relation'] = 'same';
			} else {
				// Fetch into a private ref so existing remotes and branches are untouched.
				[ $code ] = GDW_Deployer::git( $repo, [ 'fetch', '-q', $url, "+refs/heads/{$b}:refs/gdw/remote" ], 180 );
				if ( 0 !== $code ) {
					$s['relation'] = 'unknown';
				} else {
					$s['ahead']    = (int) self::val( $repo, [ 'rev-list', '--count', 'refs/gdw/remote..HEAD' ] );
					$s['behind']   = (int) self::val( $repo, [ 'rev-list', '--count', 'HEAD..refs/gdw/remote' ] );
					$base          = self::val( $repo, [ 'merge-base', 'HEAD', 'refs/gdw/remote' ] );
					$s['relation'] = '' === $base ? 'unrelated'
						: ( $s['ahead'] && $s['behind'] ? 'diverged' : ( $s['ahead'] ? 'ahead' : 'behind' ) );
				}
			}
		} elseif ( '' !== $s['head'] && $s['remote_ok'] ) {
			$s['relation'] = 'remote_missing';
		}

		set_transient( 'gdw_inspect_' . $repo['id'], $s, 15 * MINUTE_IN_SECONDS );
		return $s;
	}

	/** Plain-text facts, shared by the admin page and WP-CLI. */
	public static function describe( array $s, array $repo ) {
		$b = $repo['branch'];
		$d = [];

		$d['Folder'] = 'wp-content/' . $repo['path'] . ': '
			. ( ! $s['exists'] ? 'does not exist yet (it will be created)' : ( $s['empty'] ? 'empty' : 'contains files' ) )
			. ( $s['active'] ? '. This is the active theme.' : '' );

		if ( ! $s['git'] ) {
			$d['Local git'] = 'not a git repository';
		} else {
			$txt = '' !== $s['head']
				? 'branch ' . ( $s['local_branch'] ?: '(detached)' ) . ' at ' . substr( $s['head'], 0, 7 )
				: 'initialised, no commits';
			if ( '' !== $s['origin'] ) {
				$txt .= ', origin ' . $s['origin'];
				if ( '' !== $repo['repo_url'] && $s['origin'] !== $repo['repo_url'] ) {
					$txt .= ' (different from the configured URL)';
				}
			}
			$txt           .= $s['dirty_count'] ? ", {$s['dirty_count']} uncommitted change(s)" : ', no uncommitted changes';
			$d['Local git'] = $txt;
		}

		if ( ! $s['remote_ok'] ) {
			$d['GitHub'] = 'not reachable: ' . $s['remote_error'];
		} elseif ( '' !== $s['remote_sha'] ) {
			$d['GitHub'] = "branch {$b} at " . substr( $s['remote_sha'], 0, 7 );
		} elseif ( ! $s['remote_branches'] ) {
			$d['GitHub'] = 'the repository is empty';
		} else {
			$d['GitHub'] = "branch {$b} not found (existing branches: " . implode( ', ', array_keys( $s['remote_branches'] ) ) . ')';
		}

		$rel              = [
			'same'           => 'same commit',
			'ahead'          => "the folder is {$s['ahead']} commit(s) ahead of GitHub",
			'behind'         => "GitHub is {$s['behind']} commit(s) ahead of the folder",
			'diverged'       => "histories have diverged ({$s['ahead']} commit(s) only here, {$s['behind']} only on GitHub)",
			'unrelated'      => 'histories are unrelated (no commit in common)',
			'remote_missing' => 'the branch is not on GitHub yet',
			'unknown'        => 'could not compare (fetch failed)',
			'none'           => 'nothing to compare yet (the folder has no commits)',
		];
		$d['Relationship'] = $rel[ $s['relation'] ] ?? $s['relation'];

		return $d;
	}

	/** Which actions make sense for an inspection result. */
	public static function flags( array $s ) {
		$in_sync     = $s['git'] && 'same' === $s['relation'] && 0 === $s['dirty_count'];
		$has_files   = $s['exists'] && ! $s['empty'];
		$nothing_new = 'behind' === $s['relation'] && 0 === $s['dirty_count'];

		return [
			'in_sync'      => $in_sync,
			'use_repo'     => $s['remote_ok'] && '' !== $s['remote_sha'] && ! $in_sync,
			'publish'      => $s['remote_ok'] && $has_files && ! $in_sync && ! $nothing_new,
			// Publishing to the configured branch would need a force push.
			'needs_choice' => '' !== $s['head'] && '' !== $s['remote_sha'] && in_array( $s['relation'], [ 'behind', 'diverged', 'unrelated', 'unknown' ], true ),
		];
	}

	/** @return array{0:string,1:string} notice type, recommendation */
	public static function verdict( array $s, array $repo ) {
		$b = $repo['branch'];
		$f = self::flags( $s );
		$n = $s['dirty_count'];

		if ( ! $s['remote_ok'] ) {
			return [ 'error', "Can't reach the repository. Check the URL and deploy key, then inspect again." ];
		}
		if ( $f['in_sync'] ) {
			return [ 'success', "All set. This folder matches {$b} on GitHub, so new pushes will deploy here." ];
		}

		switch ( $s['relation'] ) {
			case 'same':
				return [ 'warning', "Connected, but the folder has {$n} uncommitted change(s). Publish them to GitHub, or use the repository's version to discard them." ];
			case 'ahead':
				return [ 'info', "The folder has {$s['ahead']} commit(s) that aren't on GitHub" . ( $n ? " plus {$n} uncommitted change(s)" : '' ) . '. Publish to push them.' ];
			case 'behind':
				return [ 'info', "GitHub has {$s['behind']} newer commit(s). Use the repository's version to update"
					. ( $n ? ", or publish your {$n} local change(s) to a new branch and merge it on GitHub." : '.' ) ];
			case 'diverged':
			case 'unrelated':
			case 'unknown':
				return [ 'warning', "This folder's git history and GitHub's {$b} don't line up. Use the repository's version (the folder is backed up first), "
					. 'or publish to a new branch and merge it on GitHub with a pull request.' ];
			case 'remote_missing':
				return [ 'info', "{$b} doesn't exist on GitHub yet. Publish to create it from this folder." ];
		}

		// The folder has no commits yet.
		if ( '' === $s['remote_sha'] ) {
			return $f['publish']
				? [ 'info', "{$b} doesn't exist on GitHub yet. Publish this folder to create it." ]
				: [ 'warning', "{$b} doesn't exist on GitHub and the folder is empty, so there is nothing to set up yet. Check the branch name." ];
		}
		if ( ! $s['exists'] || $s['empty'] ) {
			return [ 'info', "Use the repository's version to clone {$b} into this folder." ];
		}
		return [ 'info', "Both the folder and GitHub have files. Choose which one wins: use the repository's version (replaces the folder, backed up first), "
			. "or publish this folder as a new commit on top of {$b} (files that exist only on GitHub are kept)." ];
	}

	/* ---------------------------------------------------------------- */
	/* Actions                                                           */
	/* ---------------------------------------------------------------- */

	/** Make the folder match GitHub. */
	public static function use_repo( array $repo, $backup = true ) {
		$dir     = GDW_Config::abs_path( $repo );
		$preface = '';

		if ( $backup && '' !== $dir && ( self::has_files( $dir ) || is_dir( $dir . '/.git' ) ) ) {
			[ $ok, $msg ] = self::backup( $repo );
			if ( ! $ok ) {
				return GDW_Deployer::record( $repo, 'setup', false, '', "Backup failed, so nothing was changed.\n" . $msg );
			}
			$preface = "Backed up the folder to {$msg}";
		}

		$entry = GDW_Deployer::run( $repo, 'setup', true, $preface );
		self::forget( $repo['id'] );
		return $entry;
	}

	/**
	 * Commit the folder and push it.
	 *
	 * @param array $opt branch (default: configured), message, force, author_name, author_email
	 */
	public static function publish( array $repo, array $opt = [] ) {
		$dir    = GDW_Config::abs_path( $repo );
		$host   = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'wordpress';
		$target = trim( (string) ( $opt['branch'] ?? '' ) ) ?: $repo['branch'];
		$force  = ! empty( $opt['force'] );
		$msg    = trim( (string) ( $opt['message'] ?? '' ) ) ?: "Publish from {$host}";
		$name   = trim( preg_replace( '/[\r\n<>]/', '', (string) ( $opt['author_name'] ?? '' ) ) ) ?: 'Git Deploy';
		$email  = trim( preg_replace( '/[\r\n<>\s]/', '', (string) ( $opt['author_email'] ?? '' ) ) ) ?: "git-deploy@{$host}";

		if ( GDW_Config::sanitize_branch( $target ) !== $target ) {
			return GDW_Deployer::record( $repo, 'publish', false, '', "'{$target}' is not a valid branch name." );
		}
		if ( ! self::has_files( $dir ) ) {
			return GDW_Deployer::record( $repo, 'publish', false, '', 'Nothing to publish: the folder is missing or empty.' );
		}
		if ( ! function_exists( 'proc_open' ) ) {
			return GDW_Deployer::record( $repo, 'publish', false, '', 'proc_open() is disabled in PHP.' );
		}
		if ( ! GDW_Deployer::lock( $repo['id'] ) ) {
			return GDW_Deployer::record( $repo, 'publish', false, '', 'Skipped: another deploy of this repository is running.' );
		}

		$out  = [];
		$git  = [ 'git', '-c', 'safe.directory=' . $dir, '-c', 'user.name=' . $name, '-c', 'user.email=' . $email ];
		$run  = function ( array $args, $timeout = GDW_Deployer::TIMEOUT ) use ( $git, $dir, $repo ) {
			return GDW_Deployer::exec( array_merge( $git, $args ), $dir, $repo, $timeout );
		};
		$step = function ( array $args ) use ( $run, &$out ) {
			[ $code, $text ] = $run( $args );
			$out[]           = '$ git ' . implode( ' ', $args ) . ( '' !== trim( $text ) ? "\n" . GDW_Deployer::cap( $text ) : '' );
			return 0 === $code;
		};
		$val  = function ( array $args ) use ( $run ) {
			[ $code, $text ] = $run( $args, 60 );
			return 0 === $code ? trim( $text ) : '';
		};

		$ok = ( function () use ( $step, $run, $val, &$out, $dir, $repo, $target, $force, $msg ) {
			if ( ! is_dir( $dir . '/.git' ) && ! $step( [ 'init', '-q' ] ) ) {
				return false;
			}

			// Point origin at the configured repository, keeping any previous origin as origin-previous.
			$url = $repo['repo_url'];
			$cur = $val( [ 'remote', 'get-url', 'origin' ] );
			if ( '' === $url && '' === $cur ) {
				$out[] = 'No repository URL is configured.';
				return false;
			}
			if ( '' !== $url && '' === $cur && ! $step( [ 'remote', 'add', 'origin', $url ] ) ) {
				return false;
			}
			if ( '' !== $url && '' !== $cur && $cur !== $url ) {
				$ok = '' === $val( [ 'remote', 'get-url', 'origin-previous' ] )
					? $step( [ 'remote', 'rename', 'origin', 'origin-previous' ] ) && $step( [ 'remote', 'add', 'origin', $url ] )
					: $step( [ 'remote', 'set-url', 'origin', $url ] );
				if ( ! $ok ) {
					return false;
				}
			}

			[ $code, $text ] = $run( [ 'ls-remote', '--heads', 'origin' ], 60 );
			if ( 0 !== $code ) {
				$out[] = "Can't reach GitHub:\n" . trim( $text );
				return false;
			}
			preg_match_all( '#^[0-9a-f]{40}\s+refs/heads/(\S+)$#m', $text, $m );
			$heads         = $m[1];
			$target_exists = in_array( $target, $heads, true );
			// Build on the target branch, or (for a new branch) on the deploy branch so a pull request can compare them.
			$base = $target_exists ? $target : ( in_array( $repo['branch'], $heads, true ) ? $repo['branch'] : '' );

			if ( '' !== $base && ! $step( [ 'fetch', '-q', 'origin', "+refs/heads/{$base}:refs/remotes/origin/{$base}" ] ) ) {
				return false;
			}

			$fresh = '' === $val( [ 'rev-parse', '--verify', '-q', 'HEAD' ] );
			if ( $fresh ) {
				if ( '' !== $base ) {
					// No local history: put the folder's files on top of GitHub's history,
					// restoring files that exist only on GitHub (README, LICENSE, …).
					if ( ! $step( [ 'reset', '-q', "refs/remotes/origin/{$base}" ] ) ) {
						return false;
					}
					[ , $deleted ] = $run( [ 'ls-files', '-z', '--deleted' ], 60 );
					$files         = array_values( array_filter( explode( "\0", $deleted ), 'strlen' ) );
					foreach ( array_chunk( $files, 100 ) as $chunk ) {
						if ( ! $step( array_merge( [ 'checkout', '-q', '--' ], $chunk ) ) ) {
							return false;
						}
					}
				}
			} elseif ( $target_exists && ! $force ) {
				[ $code ] = $run( [ 'merge-base', '--is-ancestor', "refs/remotes/origin/{$target}", 'HEAD' ], 60 );
				if ( 0 !== $code ) {
					$out[] = "GitHub's {$target} has commits this folder doesn't. Publish to a new branch and merge it on GitHub, "
						. "use the repository's version instead, or overwrite {$target} with a force push.";
					return false;
				}
			}

			// New history: add a sensible .gitignore unless the folder or GitHub already has one.
			if ( $fresh && ! file_exists( $dir . '/.gitignore' ) ) {
				file_put_contents( $dir . '/.gitignore', self::GITIGNORE );
				$out[] = 'Created a default .gitignore';
			}

			if ( ! $step( [ 'checkout', '-q', '-B', $target ] ) || ! $step( [ 'add', '-A' ] ) ) {
				return false;
			}
			[ $code ] = $run( [ 'diff', '--cached', '--quiet' ], 60 );
			if ( 1 === $code && ! $step( [ 'commit', '-q', '-m', $msg ] ) ) {
				return false;
			}
			if ( '' === $val( [ 'rev-parse', '--verify', '-q', 'HEAD' ] ) ) {
				$out[] = 'Nothing to commit.';
				return false;
			}

			$push = [ 'push', '-u', 'origin', "HEAD:refs/heads/{$target}" ];
			if ( $force ) {
				$push[] = '--force';
			}
			if ( ! $step( $push ) ) {
				if ( preg_match( '/read.only|denied|403|not allowed/i', (string) end( $out ) ) ) {
					$out[] = 'Pushing needs write access: in GitHub open the repository → Settings → Deploy keys, and tick "Allow write access" on this site\'s key.';
				}
				return false;
			}

			if ( $target !== $repo['branch'] ) {
				$out[] = "Pushed to the new branch {$target}. Open a pull request into {$repo['branch']} on GitHub; merging it will deploy here.";
			}
			return true;
		} )();

		$head = substr( $val( [ 'log', '-1', '--format=%h %s' ] ), 0, 120 );
		GDW_Deployer::unlock( $repo['id'] );
		self::forget( $repo['id'] );

		return GDW_Deployer::record( $repo, 'publish', $ok, $head, implode( "\n", $out ) );
	}

	/* ---------------------------------------------------------------- */
	/* Unlink                                                            */
	/* ---------------------------------------------------------------- */

	/**
	 * Stop managing a folder. Its files are never touched; optionally the generated
	 * deploy key and the folder's .git (backed up first) are deleted too.
	 *
	 * @return array{ok:bool,output:string}
	 */
	public static function unlink( array $repo, array $opt = [] ) {
		$out = [];

		if ( ! empty( $opt['remove_git'] ) ) {
			$git = self::git_dir( $repo );
			if ( '' !== $git ) {
				[ $ok, $msg ] = self::backup( $repo );
				if ( ! $ok ) {
					return [ 'ok' => false, 'output' => "The backup failed, so nothing was changed: {$msg}" ];
				}
				$out[] = "Backed up the folder to {$msg}";
				if ( ! self::rmtree( $git ) ) {
					$out[] = "Could not fully remove {$git}. The repository is still linked; fix the folder's permissions and try again.";
					return [ 'ok' => false, 'output' => implode( "\n", $out ) ];
				}
				$out[] = "Removed {$git}";
			}
		}

		if ( ! empty( $opt['delete_key'] ) ) {
			$key = self::own_key( $repo );
			if ( '' !== $key ) {
				@unlink( $key );
				@unlink( $key . '.pub' );
				$out[] = file_exists( $key ) ? "Could not delete the deploy key {$key}" : "Deleted the deploy key {$key}";
			}
		}

		self::forget( $repo['id'] );
		GDW_Config::delete_repo( $repo['id'] );
		$out[] = "wp-content/{$repo['path']} is no longer managed by Git Deploy. Its files were not changed.";
		return [ 'ok' => true, 'output' => implode( "\n", $out ) ];
	}

	/** The key this plugin generated for $repo, if it exists and no other repository uses it. */
	public static function own_key( array $repo ) {
		$key = GDW_Config::key_dir() . '/' . $repo['id'];
		if ( ! is_file( $key ) || GDW_Config::ssh_key( $repo ) !== $key ) {
			return '';
		}
		foreach ( GDW_Config::repos() as $other ) {
			if ( $other['id'] !== $repo['id'] && GDW_Config::ssh_key( $other ) === $key ) {
				return '';
			}
		}
		return $key;
	}

	/** The folder's .git directory, if it is a real directory inside wp-content; else ''. */
	public static function git_dir( array $repo ) {
		$dir = GDW_Config::abs_path( $repo );
		$git = $dir . '/.git';
		if ( '' === $dir || is_link( $git ) || ! is_dir( $git ) ) {
			return '';
		}
		$real    = realpath( $git );
		$content = realpath( WP_CONTENT_DIR );
		return ( $real && $content && 0 === strpos( $real, $content . '/' ) ) ? $git : '';
	}

	/** Delete a directory tree without following symlinks. */
	private static function rmtree( $dir ) {
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() && ! $item->isLink() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		return @rmdir( $dir );
	}

	/** tar.gz of the folder (including .git) in the backup folder. @return array{0:bool,1:string} */
	public static function backup( array $repo ) {
		$dir  = GDW_Config::abs_path( $repo );
		$bdir         = GDW_Config::backup_dir();
		[ $ok, $msg ] = GDW_Deployer::ensure_dir( $bdir );
		if ( ! $ok ) {
			return [ false, $msg ];
		}

		$file            = $bdir . '/' . $repo['id'] . '-' . gmdate( 'Ymd-His' ) . '.tar.gz';
		[ $code, $text ] = GDW_Deployer::exec( [ 'tar', '-czf', $file, '-C', dirname( $dir ), basename( $dir ) ], sys_get_temp_dir(), $repo, 600 );
		if ( 0 !== $code ) {
			@unlink( $file );
			return [ false, trim( $text ) ];
		}
		@chmod( $file, 0600 );
		return [ true, $file ];
	}
}
