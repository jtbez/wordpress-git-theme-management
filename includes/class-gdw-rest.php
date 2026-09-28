<?php
/**
 * Webhook: POST /wp-json/git-deploy/v1/<repo-id>
 *
 * Accepts GitHub push webhooks or a signed request from GitHub Actions.
 * Authentication is an HMAC-SHA256 signature of the raw body in X-Hub-Signature-256.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GDW_Rest {

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes() {
		register_rest_route(
			'git-deploy/v1',
			'/(?P<id>[a-z0-9_-]+)',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle' ],
				'permission_callback' => '__return_true', // Signature is verified in handle().
			]
		);
	}

	public static function handle( WP_REST_Request $request ) {
		$repo = GDW_Config::get( GDW_Config::sanitize_id( $request['id'] ) );
		$body = $request->get_body();

		// Unknown, disabled and badly signed requests all look the same from outside.
		$secret = ( $repo && $repo['enabled'] ) ? $repo['secret'] : '';
		$given  = (string) $request->get_header( 'X-Hub-Signature-256' );
		if ( '' === $secret || ! hash_equals( 'sha256=' . hash_hmac( 'sha256', $body, $secret ), $given ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid signature' ], 401 );
		}

		if ( 'ping' === $request->get_header( 'X-GitHub-Event' ) ) {
			return new WP_REST_Response( [ 'ok' => true, 'message' => 'pong' ], 200 );
		}

		$payload = json_decode( $body, true );
		$payload = is_array( $payload ) ? $payload : [];
		$ref     = (string) ( $payload['ref'] ?? '' );
		$want    = 'refs/heads/' . $repo['branch'];

		if ( $ref !== $want ) {
			return new WP_REST_Response( [ 'ok' => true, 'skipped' => "ref '{$ref}' is not {$want}" ], 202 );
		}
		if ( ! empty( $payload['deleted'] ) ) {
			return new WP_REST_Response( [ 'ok' => true, 'skipped' => 'branch was deleted' ], 202 );
		}

		$sha = preg_replace( '/[^0-9a-f]/i', '', (string) ( $payload['after'] ?? '' ) );

		if ( 'queue' === $repo['mode'] ) {
			GDW_Deployer::queue( $repo['id'], $sha );
			return new WP_REST_Response( [ 'ok' => true, 'queued' => $sha ], 202 );
		}

		if ( ! function_exists( 'proc_open' ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'proc_open is disabled; use queue mode' ], 500 );
		}

		// Reply to GitHub first (it gives up after 10s), then run git.
		register_shutdown_function(
			function () use ( $repo ) {
				ignore_user_abort( true );
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				}
				GDW_Deployer::run( $repo, 'webhook' );
			}
		);

		return new WP_REST_Response( [ 'ok' => true, 'started' => $sha ], 202 );
	}
}
