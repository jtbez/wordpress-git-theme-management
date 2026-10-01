<?php
/**
 * Deploy theme and plugin folders from GitHub with git.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GDW_CLI {

	/**
	 * List configured repositories.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$fields = [ 'id', 'path', 'branch', 'mode', 'enabled', 'pending', 'last_deploy' ];
		$rows   = [];
		foreach ( GDW_Config::repos() as $r ) {
			$last   = GDW_Deployer::log( $r['id'] )[0] ?? null;
			$rows[] = [
				'id'          => $r['id'],
				'path'        => 'wp-content/' . $r['path'],
				'branch'      => $r['branch'],
				'mode'        => $r['mode'],
				'enabled'     => $r['enabled'] ? 'yes' : 'no',
				'pending'     => GDW_Deployer::pending( $r['id'] ) ? 'yes' : 'no',
				'last_deploy' => $last ? ( $last['ok'] ? 'OK ' : 'FAILED ' ) . gmdate( 'Y-m-d H:i', $last['time'] ) . ' ' . $last['head'] : '-',
			];
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, $fields );
	}

	/**
	 * Deploy now: fetch and check out the configured branch.
	 *
	 * Folders that were never set up are left alone; use `setup` for those.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Repository IDs. Defaults to every enabled repository.
	 *
	 * ## EXAMPLES
	 *
	 *     wp git-deploy pull
	 *     wp git-deploy pull site1-theme
	 */
	public function pull( $args ) {
		$failed = 0;
		foreach ( $this->targets( $args ) as $repo ) {
			GDW_Deployer::take_pending( $repo['id'] );
			$failed += $this->report( $repo, GDW_Deployer::run( $repo, 'wp-cli' ) ) ? 0 : 1;
		}
		if ( $failed ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Run deploys queued by the webhook. Use from cron when a repository is in queue mode.
	 *
	 * ## EXAMPLES
	 *
	 *     * * * * * wp --path=/var/www/site1 git-deploy run-pending --quiet
	 *
	 * @subcommand run-pending
	 */
	public function run_pending( $args ) {
		foreach ( GDW_Config::repos() as $repo ) {
			if ( $repo['enabled'] && GDW_Deployer::take_pending( $repo['id'] ) ) {
				$this->report( $repo, GDW_Deployer::run( $repo, 'queue' ) );
			}
		}
	}

	/**
	 * Show recent deploy output.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Repository ID.
	 *
	 * [--count=<n>]
	 * : Number of entries.
	 * ---
	 * default: 1
	 * ---
	 */
	public function log( $args, $assoc ) {
		$repo = $this->targets( $args )[0];
		$log  = array_slice( GDW_Deployer::log( $repo['id'] ), 0, max( 1, (int) ( $assoc['count'] ?? 1 ) ) );
		if ( ! $log ) {
			WP_CLI::log( 'No deploys yet.' );
			return;
		}
		foreach ( $log as $e ) {
			WP_CLI::log( sprintf( '== %s %s via %s %s', $e['ok'] ? 'OK' : 'FAILED', gmdate( 'Y-m-d H:i:s', $e['time'] ), $e['trigger'], $e['head'] ) );
			WP_CLI::log( $e['output'] . "\n" );
		}
	}

	/**
	 * Compare a repository's folder with its GitHub branch and suggest what to do.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Repository ID.
	 */
	public function inspect( $args ) {
		$repo = $this->targets( $args )[0];
		$s    = GDW_Setup::inspect( $repo );
		foreach ( GDW_Setup::describe( $s, $repo ) as $label => $text ) {
			WP_CLI::log( str_pad( $label . ':', 14 ) . $text );
		}
		foreach ( $s['dirty'] as $line ) {
			WP_CLI::log( '              ' . $line );
		}
		[ $type, $advice ] = GDW_Setup::verdict( $s, $repo );
		WP_CLI::log( '' );
		'success' === $type ? WP_CLI::success( $advice ) : WP_CLI::log( $advice );
	}

	/**
	 * Set up a folder: make it match GitHub, or publish it to GitHub.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Repository ID.
	 *
	 * --use=<side>
	 * : Which copy wins.
	 * ---
	 * options:
	 *   - repo
	 *   - folder
	 * ---
	 *
	 * [--branch=<branch>]
	 * : With --use=folder: branch to push to (default: the configured branch).
	 *
	 * [--message=<message>]
	 * : With --use=folder: commit message.
	 *
	 * [--force]
	 * : With --use=folder: overwrite the branch on GitHub if its history differs.
	 *
	 * [--[no-]backup]
	 * : With --use=repo: back up the folder first (default: yes).
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp git-deploy setup live-theme --use=folder --message="Initial import"
	 *     wp git-deploy setup live-theme --use=folder --branch=import-from-server
	 *     wp git-deploy setup new-theme --use=repo
	 */
	public function setup( $args, $assoc ) {
		$repo = $this->targets( $args )[0];
		$use  = $assoc['use'];

		if ( 'repo' === $use ) {
			WP_CLI::confirm( "Replace wp-content/{$repo['path']} with {$repo['branch']} from GitHub?", $assoc );
			$e = GDW_Setup::use_repo( $repo, WP_CLI\Utils\get_flag_value( $assoc, 'backup', true ) );
		} else {
			$branch = $assoc['branch'] ?? $repo['branch'];
			WP_CLI::confirm( "Commit wp-content/{$repo['path']} and push it to {$branch}" . ( ! empty( $assoc['force'] ) ? ' (FORCE)' : '' ) . '?', $assoc );
			$user = wp_get_current_user();
			$e    = GDW_Setup::publish(
				$repo,
				[
					'branch'       => $branch,
					'message'      => $assoc['message'] ?? '',
					'force'        => ! empty( $assoc['force'] ),
					'author_name'  => $user->exists() ? $user->display_name : '',
					'author_email' => $user->exists() ? $user->user_email : '',
				]
			);
		}

		if ( ! $this->report( $repo, $e ) ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Stop managing a folder (or cancel a setup). The folder's files are kept.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Repository ID.
	 *
	 * [--delete-key]
	 * : Also delete the deploy key this plugin generated for the repository.
	 *
	 * [--remove-git]
	 * : Also delete the folder's .git, after backing up the whole folder.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp git-deploy unlink old-theme --delete-key
	 *     wp git-deploy unlink old-theme --delete-key --remove-git --yes
	 */
	public function unlink( $args, $assoc ) {
		$repo = $this->targets( $args )[0];
		if ( $repo['locked'] ) {
			WP_CLI::error( "{$repo['id']} is defined in wp-config.php (GDW_REPOS). Remove it there instead." );
		}
		$remove_git = ! empty( $assoc['remove-git'] );
		WP_CLI::confirm( "Unlink wp-content/{$repo['path']} from Git Deploy" . ( $remove_git ? ' and remove its .git' : '' ) . '?', $assoc );

		$e = GDW_Setup::unlink( $repo, [ 'delete_key' => ! empty( $assoc['delete-key'] ), 'remove_git' => $remove_git ] );
		WP_CLI::log( $e['output'] );
		$e['ok'] ? WP_CLI::success( "Unlinked {$repo['id']}. Remove its deploy key and webhook on GitHub too." ) : WP_CLI::error( "Could not unlink {$repo['id']}." );
	}

	private function targets( array $ids ) {
		if ( ! $ids ) {
			return array_filter(
				GDW_Config::repos(),
				function ( $r ) {
					return $r['enabled'];
				}
			);
		}
		$out = [];
		foreach ( $ids as $id ) {
			$repo = GDW_Config::get( GDW_Config::sanitize_id( $id ) );
			if ( ! $repo ) {
				WP_CLI::error( "Unknown repository '{$id}'. See: wp git-deploy list" );
			}
			$out[] = $repo;
		}
		return $out;
	}

	private function report( array $repo, array $e ) {
		if ( '' !== $e['output'] ) {
			WP_CLI::log( $e['output'] );
		}
		if ( $e['ok'] ) {
			WP_CLI::success( "{$repo['id']} deployed: {$e['head']}" );
		} else {
			WP_CLI::warning( "{$repo['id']} deploy failed." );
		}
		return $e['ok'];
	}
}
