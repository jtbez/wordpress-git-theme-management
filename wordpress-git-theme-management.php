<?php
/**
 * Plugin Name:       WordPress Git Theme Management
 * Plugin URI:        https://github.com/jtbez/wordpress-git-theme-management
 * Description:       Keep theme in sync with a GitHub branch using git. Clone the repo via WordPress Admin and trigger the repository update using a webhook.
 * Version:           1.2.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Joseph Berry (jtbez)
 * Author URI:        https://github.com/jtbez
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/jtbez/wordpress-git-theme-management
 * Text Domain:       wordpress-git-theme-management
 */

/*
 * WordPress Git Theme Management
 * Copyright (C) 2026 Joseph Berry (jtbez)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see <https://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GDW_VERSION', '1.2.1' ); // Keep in sync with the Version header; the release workflow checks both.
define( 'GDW_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-gdw-config.php';
require_once __DIR__ . '/includes/class-gdw-deployer.php';
require_once __DIR__ . '/includes/class-gdw-setup.php';
require_once __DIR__ . '/includes/class-gdw-rest.php';
require_once __DIR__ . '/includes/class-gdw-updater.php';

GDW_Rest::init();

// Create the private key and backup folders on activation. Skipped under WP-CLI,
// which may run as a different user than PHP and would leave folders PHP can't write.
register_activation_hook(
	__FILE__,
	function () {
		if ( PHP_SAPI !== 'cli' ) {
			GDW_Deployer::ensure_dirs();
		}
	}
);
GDW_Updater::init(); // Update checks also run from cron, so this is not admin-only.

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-gdw-admin.php';
	GDW_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-gdw-cli.php';
	WP_CLI::add_command( 'git-deploy', 'GDW_CLI' );
}
