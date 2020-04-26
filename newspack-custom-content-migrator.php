<?php
/**
 * Plugin Name: Newspack Custom Content Migrator
 * Author:	    Automattic
 * Author URI:  https://automattic.com
 * Version:	    0.3.0
 *
 * @package	 Newspack_Custom_Content_Migrator
 */

namespace NewspackCustomContentMigrator;

// Don't do anything outside WP CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require 'vendor/autoload.php';
require_once( ABSPATH . 'wp-settings.php' );

PluginSetup::setup_wordpress_importer();
PluginSetup::register_migrators( array(
	Migrator\General\PostsMigrator::class,
	Migrator\General\MenusMigrator::class,
	Migrator\General\CssMigrator::class,
	Migrator\General\ContentConverterPluginMigrator::class,
	Migrator\General\SettingsMigrator::class,
	// Migrator\General\InlineFeaturedImageMigrator::class,
	// Migrator\General\SubtitleMigrator::class,

	// Migrator\PublisherSpecific\AsiaTimesMigrator::class,
	// Migrator\PublisherSpecific\SahanJournalMigrator::class,
	// Migrator\PublisherSpecific\HKFPMigrator::class,
) );
