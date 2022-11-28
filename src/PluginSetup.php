<?php

namespace NewspackCustomContentMigrator;

use \WP_CLI;

/**
 * PluginSetup class.
 */
class PluginSetup {
	/**
	 * Registers migrators' commands.
	 *
	 * @param $migrator_classes Array of Command\InterfaceCommand classes.
	 */
	public static function register_migrators( $migrator_classes ) {
		foreach ( $migrator_classes as $migrator_class ) {
			$migrator = $migrator_class::get_instance();
			if ( $migrator instanceof Command\InterfaceCommand ) {
				$migrator->register_commands();
			}
		}
	}

	/**
	 * Checks whether wordpress-importer is active and valid, and if not, installs and activates it.
	 */
	public static function setup_wordpress_importer() {
		$plugin_installer = \NewspackCustomContentMigrator\PluginInstaller::get_instance();
		$plugin_slug      = 'wordpress-importer';
		$is_installed     = $plugin_installer->is_installed( $plugin_slug );
		$is_active        = $plugin_installer->is_active( $plugin_slug );

		if ( $is_installed && ! $is_active ) {
			WP_CLI::line( sprintf( 'Activating the %s plugin now...', $plugin_slug ) );
			try {
				$plugin_installer->activate( $plugin_slug );
			} catch ( \Exception $e ) {
				WP_CLI::error( 'WP Importer Plugin activation error: ' . $e->getMessage() );
			}
		} elseif ( ! $is_installed ) {
			WP_CLI::line( sprintf( 'Installing and activating the %s plugin now...', $plugin_slug ) );
			try {
				$plugin_installer->install( $plugin_slug );
				$plugin_installer->activate( $plugin_slug );
			} catch ( \Exception $e ) {
				WP_CLI::error( 'WP Importer Plugin installation error: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Checks whether Co-Authors-Plus is active and valid, and if not, installs and activates it.
	 */
	public static function setup_coauthors_plus() {
		$plugin_installer = \NewspackCustomContentMigrator\PluginInstaller::get_instance();
		$plugin_slug      = 'co-authors-plus';
		$is_installed     = $plugin_installer->is_installed( $plugin_slug );
		$is_active        = $plugin_installer->is_active( $plugin_slug );

		if ( $is_installed && ! $is_active ) {
			WP_CLI::line( sprintf( 'Activating the %s plugin now...', $plugin_slug ) );
			try {
				$plugin_installer->activate( $plugin_slug );
			} catch ( \Exception $e ) {
				WP_CLI::error( 'Plugin activation error: ' . $e->getMessage() );
			}
		} elseif ( ! $is_installed ) {
			WP_CLI::line( sprintf( 'Installing and activating the %s plugin now...', $plugin_slug ) );
			try {
				$plugin_installer->install( $plugin_slug );
				$plugin_installer->activate( $plugin_slug );
			} catch ( \Exception $e ) {
				WP_CLI::error( 'Plugin installation error: ' . $e->getMessage() );
			}
		}
	}
}
