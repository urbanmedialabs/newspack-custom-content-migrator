<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \CoAuthors_Guest_Authors;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Bethesda Mag.
 */
class BethesdaMagMigrator implements InterfaceMigrator {
	const DELETE_LOGS = 'bethesda_duplicate_posts_delete.log';

	/**
	 * @var PostsLogic.
	 */
	private $posts_migrator_logic;

	/**
	 * @var CoAuthorPlusLogic $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var CoAuthors_Guest_Authors $coauthors_guest_authors
	 */
	private $coauthors_guest_authors;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_migrator_logic = new PostsLogic();
		$this->coauthorsplus_logic  = new CoAuthorPlusLogic();
		$this->coauthors_guest_authors = new CoAuthors_Guest_Authors();
	}

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator bethesda-remove-duplicated-posts',
			array( $this, 'bethesda_remove_duplicated_posts' ),
			array(
				'shortdesc' => 'Remove duplicated posts.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator bethesda-migrate-co-authors-from-meta',
			array( $this, 'bethesda_migrate_co_authors_from_meta' ),
			array(
				'shortdesc' => 'Remove duplicated posts.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator bethesda-guest-author-audit',
			array( $this, 'cmd_guest_author_audit' ),
			array(
				'shortdesc' => 'Replaces Guest Authors based on a mapping provided by the publisher',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator bethesda-create-terms-for-multiple-taxonomies',
			[ $this, 'create_term_dupes_for_taxonomies' ],
			[
				'shortdesc' => 'Creates multiple terms for any term which has multiple taxonomies attached.',
				'synopsis' => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator bethesda-migrate-best-of-content',
			[ $this, 'bethesda_migrate_best_of_content' ],
			[
				'shortdesc' => 'Best of content is a CPT, which needs to be migrated to posts',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator bethesda-migrate-profile-content',
			[ $this, 'bethesda_migrate_profile_content' ],
			[
				'shortdesc' => 'Profile content which needs to be migrated.',
				'synopsis' => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator bethesda-remove-duplicated-posts`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function bethesda_remove_duplicated_posts( $args, $assoc_args ) {
		global $wpdb;

		$post_ids_to_delete = array();

		$posts_table = $wpdb->prefix . 'posts';

		$sql = "SELECT post_title, post_date, GROUP_CONCAT(ID ORDER BY ID) AS duplicate_ids, COUNT(*)
		FROM {$posts_table}
		where post_status = 'publish' and post_type in ('post', 'page')
		GROUP BY post_title, post_content, post_date
		HAVING COUNT(*) > 1 ;";
		// phpcs:ignore -- false positive, all params are fully sanitized.
		$results = $wpdb->get_results( $sql );

		foreach ( $results as $result ) {
			$ids = explode( ',', $result->duplicate_ids );
			if ( 2 === count( $ids ) ) {
				$post_ids_to_delete[ $ids[0] ] = array( $ids[1] ); // Deleting the last one imported.
			} else {
				// Some posts are duplicated more than once.
				// We need to make sure that we're deleting the right duplicate.
				$original_post = get_post( $ids[0] );
				foreach ( $ids as $index => $id ) {
					// skip original post.
					if ( 0 === $index ) {
						continue;
					}

					$post = get_post( $id );
					if ( $original_post->post_content === $post->post_content ) {
						if ( ! isset( $post_ids_to_delete[ $ids[0] ] ) ) {
							$post_ids_to_delete[ $ids[0] ] = array();
						}

						$post_ids_to_delete[ $ids[0] ][] = $id;
					}
				}
			}
		}

		foreach ( $post_ids_to_delete as $original_id => $ids ) {
			foreach ( $ids as $post_id_to_delete ) {
				$this->log( self::DELETE_LOGS, sprintf( "Deleting post #%d as it's a duplicate of #%d", $post_id_to_delete, $original_id ) );
				wp_delete_post( $post_id_to_delete );
			}
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator bethesda-migrate-co-authors-from-meta`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function bethesda_migrate_co_authors_from_meta( $args, $assoc_args ) {
		$this->posts_migrator_logic->throttled_posts_loop(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			),
			function( $post ) {
				$co_authors_ids       = array();
				$author_meta          = get_post_meta( $post->ID, 'bm_author', true );
				$authors_to_not_split = array(
					'By Alicia Klaffky, Kensington',
					'By Robert Karn, Boyds',
					'By Carole Sugarman, @CaroleSugarman',
				);

				$co_authors_to_add = array();

				if ( ! empty( $author_meta ) ) {
					$cleaned_author_name = $this->clean_author_name( trim( wp_strip_all_tags( $author_meta ) ) );

					// Skip splitting authors with 'School' as they contain only one author name and the name of the high school.
					// Skip splitting specific author names.
					// Skip splitting author names that starts with or ends with given words.
					if (
						! $this->str_contains( $cleaned_author_name, 'school' )
						&& ! $this->str_contains( $cleaned_author_name, 'Adult Short Story Winner' )
						&& ! $this->str_contains( $cleaned_author_name, 'Washington, D.C.' )
						&& ! $this->str_contains( $cleaned_author_name, 'Academie de Cuisine' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Potomac' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Rockville' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Chevy Chase' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', MD' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Gaithersburg' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Bethesda' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Arlington, VA' )
						&& ! $this->str_starts_with( 'Story and photos by', $author_meta )
						&& ! $this->str_starts_with( 'Text and photos by', $author_meta )
						&& ! in_array( $author_meta, $authors_to_not_split, true )
						&& ( $this->str_contains( $cleaned_author_name, ' and ' ) || $this->str_contains( $cleaned_author_name, ', ' ) || $this->str_contains( $cleaned_author_name, 'Follow @' ) )
					) {
						$co_authors_names = preg_split( '/(, | and | & |Follow @[^\s]+)/', $cleaned_author_name );
						foreach ( $co_authors_names as $ca ) {
							$co_authors_to_add[] = $ca;
						}
					} else {
						$co_authors_to_add = array( $cleaned_author_name );
					}
				}

				if ( ! empty( $co_authors_to_add ) ) {
					// add co-authors and link them to the post.
					foreach ( $co_authors_to_add as $co_author_to_add ) {
						$co_authors_ids[] = $this->coauthorsplus_logic->create_guest_author( array( 'display_name' => $co_author_to_add ) );
					}

					// Assign co-atuhors to the post in question.
					$this->coauthorsplus_logic->assign_guest_authors_to_post( $co_authors_ids, $post->ID );
					WP_CLI::line( sprintf( 'Adding co-authors to the post %d: %s', $post->ID, join( ', ', $co_authors_to_add ) ) );
				}
			}
		);

		wp_cache_flush();
	}

	public function bethesda_migrate_profile_content( $args, $assoc_args ) {
		$xml_path = get_home_path() . 'bethesdamagazine.formatted.WordPress.2022-06-01.xml';
		$xml = new \DOMDocument();
		$xml->loadXML( file_get_contents( $xml_path ) );

		$rss = $xml->getElementsByTagName( 'rss' )->item( 0 );

		$channel_children = $rss->childNodes->item( 1 )->childNodes;

		$profiles_term_id = wp_create_category( 'profiles' );

		$posts = [];
		$authors = [];
		$progress = WP_CLI\Utils\make_progress_bar( 'Processing XML items', $channel_children->count() );
		foreach ( $channel_children as $child ) {
			/* @var \DOMNode $child */
			if ( 'wp:author' === $child->nodeName ) {
				$author = $this->handle_xml_author( $child );
				$authors[ $author->user_login ] = $author;
			} else if ( 'item' === $child->nodeName ) {
				$posts[] = $this->handle_xml_item_two( $child, $profiles_term_id, $authors );
			}
			$progress->tick();
		}
		$progress->finish();

		$this->process_posts( $posts, [ $profiles_term_id ] );
	}
	/**
	 * Handles XML <item>'s from file to import as Posts.
	 *
	 * @param \DOMNode $item XML <item>.
	 * @param int $best_of_term_id Parent category ID.
	 * @param array $authors Recently imported authors.
	 *
	 * @return array
	 */
	private function handle_xml_item_two( \DOMNode $item, int $best_of_term_id = 0, array $authors = [] ) {
		global $wpdb;
		$featured_images_sql = "SELECT ID, guid FROM bak_wp_posts WHERE post_type = 'attachment'";
		$featured_images = $wpdb->get_results( $featured_images_sql, OBJECT_K );

		$post                      = [
			'post_type'     => 'post',
			'meta_input'    => [
				'newspack_listings_hide_author' => 1,
				'newspack_listings_hide_publish_date' => 1,
				'newspack_listings_hide_parents' => '',
				'newspack_listings_hide_children' => '',
			],
		];
		$categories                = [];
		$tags                      = [];
		$post_content_template     = '{specialty}{full_address}{phone}{email}{link}{description}{other}';
		$specialty_template        = '<h4>{content}</h4><br>';
		$full_address_template     = '<address>{address}{city}{zipcode}</address><br>';
		$address_template          = '{content}<br>';
		$city_template             = '{content} ';
		$description_template      = '</p>{content}</p><br>';
		$phone_template            = '{content}<br>';
		$email_template            = '<a href="mailto:{email}">{email}</a>';
		$link_template             = '<a href="{url}" target=_blank>{url}</a><br>';
		$other_template            = '<p>{content}</p><br>';
		$specialty                 = '';
		$full_address              = '';
		$address                   = '';
		$city                      = '';
		$zipcode                   = '';
		$description               = '';
		$phone                     = '';
		$email                     = '';
		$link                      = '';
		$other                     = '';

		foreach ( $item->childNodes as $child ) {
			/* @var \DOMNode $child */
			if ( 'title' === $child->nodeName ) {
				$post['post_title'] = $child->nodeValue;
			}

			if ( 'dc:creator' === $child->nodeName ) {
				$post['post_author'] = $authors[ $child->nodeValue ]->ID ?? 0;
			}

			if ( 'wp:post_date' === $child->nodeName ) {
				$post['post_date'] = $child->nodeValue;
			}

			if ( 'wp:post_date_gmt' === $child->nodeName ) {
				$post['post_date_gmt'] = $child->nodeValue;
			}

			if ( 'wp:post_modified' === $child->nodeName ) {
				$post['post_modified'] = $child->nodeValue;
			}

			if ( 'wp:post_modified_gmt' === $child->nodeName ) {
				$post['post_modified_gmt'] = $child->nodeValue;
			}

			if ( 'wp:comment_status' === $child->nodeName ) {
				$post['comment_status'] = $child->nodeValue;
			}

			if ( 'wp:ping_status' === $child->nodeName ) {
				$post['ping_status'] = $child->nodeValue;
			}

			if ( 'wp:status' === $child->nodeName ) {
				$post['post_status'] = $child->nodeValue;
			}

			if ( 'wp:post_name' === $child->nodeName ) {
				$post['post_name'] = $child->nodeValue;
			}

			if ( 'wp:post_parent' === $child->nodeName ) {
				$post['post_parent'] = $child->nodeValue;
			}

			if ( 'wp:menu_order' === $child->nodeName ) {
				$post['menu_order'] = $child->nodeValue;
			}

			if ( 'wp:post_password' === $child->nodeName ) {
				$post['post_password'] = $child->nodeValue;
			}

			if ( 'wp:postmeta' === $child->nodeName ) {
				$meta_key   = $child->childNodes->item( 1 )->nodeValue;
				$meta_value = trim( $child->childNodes->item( 3 )->nodeValue );

				if ( empty( $meta_value ) || str_starts_with( $meta_value, 'field_' ) ) {
					continue;
				}

				switch ( $meta_key ) {
					case '_thumbnail_id':
						if ( array_key_exists( $meta_value, $featured_images ) ) {
							$guid = $featured_images[$meta_value]->guid;
							$path = parse_url( $guid, PHP_URL_PATH );

							$new_post_id = $wpdb->get_row( "SELECT ID FROM $wpdb->posts WHERE guid LIKE '%$path' LIMIT 1" );

							if ( ! is_null( $new_post_id ) ) {
								$post['meta_input']['_thumbnail_id'] = $new_post_id->ID;
								$post['meta_input']['newspack_featured_image_position'] = 'above';
							}
						}
						break;
					case 'bm_specialty':
						$specialty = strtr(
							$specialty_template,
							[
								'{content}' => $meta_value,
							]
						);
						break;
					case 'bm_address':
						$address = strtr(
							$address_template,
							[
								'{content}' => $meta_value,
							]
						);
						break;
					case 'bm_city':
						$city = strtr(
							$city_template,
							[
								'{content}' => $meta_value,
							]
						);
						break;
					case 'bm_zipcode':
						$zipcode = $meta_value;
						break;
					case 'bm_phone':
						$phone = strtr(
							$phone_template,
							[
								'{content}' => $meta_value,
							]
						);
						break;
					case 'bm_email':
						$email = strtr(
							$email_template,
							[
								'{email}' => $meta_value,
							]
						);
						break;
					case 'bm_url':
						$link = strtr(
							$link_template,
							[
								'{url}' => $meta_value,
							]
						);
						break;
					case 'bm_description':
						$text = explode( "\n", $meta_value );
						$post['post_excerpt'] = array_shift( $text );
						$description = strtr(
							$description_template,
							[
								'{content}' => $meta_value,
							]
						);
						break;
					case 'bm_other':
						$other = strtr(
							$other_template,
							[
								'{content}' => $meta_value,
							]
						);
						break;
				}
			}

			if ( ! empty( $address ) || ! empty( $city ) || ! empty( $zipcode ) ) {
				$full_address = strtr(
					$full_address_template,
					[
						'{address}' => $address,
						'{city}' => $city,
						'{zipcode}' => $zipcode,
					]
				);
			}

			$post['post_content'] = strtr(
				$post_content_template,
				[
					'{specialty}' => $specialty,
					'{full_address}' => $full_address,
					'{phone}' => $phone,
					'{email}' => $email,
					'{link}' => $link,
					'{description}' => $description,
					'{other}'     => $other,
				]
			);

			if ( 'category' === $child->nodeName ) {
				$categories[] = [
					'cat_name'          => htmlspecialchars_decode( $child->nodeValue ),
					'category_nicename' => $child->attributes->getNamedItem( 'nicename' )->nodeValue,
					'category_parent'   => $best_of_term_id,
				];
			}
		}

		return [
			'post'       => $post,
			'tags'       => $tags,
			'categories' => $categories,
		];
	}

	public function cmd_guest_author_audit() {
		$path = get_home_path() . 'guest_author_audit.csv';
		$handle = fopen( $path, 'r' );

		$header = fgetcsv( $handle, 0 );

		while ( ! feof( $handle ) ) {
			$row = array_combine( $header, fgetcsv( $handle, 0 ) );

			$destination_guest_author_id = $this->get_or_create_guest_author( $row['new_name_1'] );

			$original_guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'post_name', sanitize_title( $row['existing_name'] ) );

			if ( false === $original_guest_author ) {
				WP_CLI::log( "GUEST AUTHOR NOT FOUND: {$row['existing_name']}" );
				continue;
			}

			WP_CLI::log( "EXISTING AUTHOR NAME: {$row['existing_name']}\t$original_guest_author->ID" );
			$post_ids = $this->coauthorsplus_logic->get_all_posts_for_guest_author( $original_guest_author->ID );

			$additional_guest_author_id = null;

			if ( ! empty( $row['new_name_2'] ) ) {
				$additional_guest_author_id = $this->get_or_create_guest_author( $row['new_name_2'] );
			}

			foreach ( $post_ids as $post_id ) {
				$guest_authors = [ $destination_guest_author_id ];

				if ( ! is_null( $additional_guest_author_id ) ) {
					$guest_authors[] = $additional_guest_author_id;
				}

				WP_CLI::log( 'REASSIGNING: ' . implode( ',', $guest_authors ) . ' Post ID: ' . $post_id );
				$this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_authors, $post_id );
			}

			$this->coauthors_guest_authors->delete( $original_guest_author->ID );
		}
	}

	/**
	 * @param string $full_name
	 *
	 * @return int
	 */
	private function get_or_create_guest_author( string $full_name ) {
		WP_CLI::log( "GUEST AUTHOR NAME: $full_name" );
		$guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'post_name', sanitize_title( $full_name ) );

		if ( false !== $guest_author ) {
			WP_CLI::log( "EXISTS! $guest_author->ID" );
			return $guest_author->ID;
		}

		$exploded = explode( ' ', $full_name );
		$last_name = array_pop( $exploded );
		$first_name = implode( ' ', $exploded );

		WP_CLI::log( 'CREATING' );
		return $this->coauthorsplus_logic->create_guest_author(
			[
				'display_name' => $full_name,
				'first_name' => $first_name,
				'last_name' => $last_name,
			]
		);
	}

	/**
	 * This function will find any terms which have multiple taxonomies assigned.
	 * In other words, there will be one taxonomy for every term.
	 *
	 * @param string[] $args WP CLI positional arguments.
	 * @param string[] $assoc_args WP CLI optional argumemnts.
	 */
	public function create_term_dupes_for_taxonomies( $args, $assoc_args ) {
		global $wpdb;

		$terms_with_multiple_taxonomies_sql = "SELECT
			    t.term_id,
			    t.name,
			    t.slug,
			    COUNT(DISTINCT t.term_id) as term_counter,
			    GROUP_CONCAT( DISTINCT tt.taxonomy ORDER BY tt.taxonomy ) as grouped,
			    COUNT(DISTINCT tt.term_taxonomy_id) as tt_counter
			FROM $wpdb->terms t
			LEFT JOIN $wpdb->term_taxonomy tt on t.term_id = tt.term_id
			WHERE tt.taxonomy IN (
				'category',
				'post_tag',
				'nav_menu',
				'newspack_lstngs_plc',
				'newspack_popups_taxonomy',
				'newspack_spnsrs_tax',
				'wp_theme'
			)
			GROUP BY t.term_id
			HAVING tt_counter > term_counter
			ORDER BY tt_counter DESC";

		$terms_with_multiple_taxonomies = $wpdb->get_results( $terms_with_multiple_taxonomies_sql );

		$progress = WP_CLI\Utils\make_progress_bar( 'Processing terms...', count( $terms_with_multiple_taxonomies ) );
		foreach ( $terms_with_multiple_taxonomies as $term ) {
			$taxonomies = explode( ',', $term->grouped );
			array_shift( $taxonomies );

			foreach ( $taxonomies as $taxonomy ) {
				$result = $wpdb->insert(
					$wpdb->terms,
					[
						'name' => $term->name,
						'slug' => $term->slug,
					]
				);

				if ( false !== $result ) {

					$latest_term_sql = "SELECT term_id FROM $wpdb->terms WHERE name = '$term->name' AND slug = '$term->slug' ORDER BY term_id DESC LIMIT 1";
					$latest_term = $wpdb->get_row( $latest_term_sql );

					$result = $wpdb->insert(
						$wpdb->term_taxonomy,
						[
							'term_id' => $latest_term->term_id,
							'taxonomy' => $taxonomy,
						]
					);

					if ( false !== $result ) {
						$new_term_taxonomy_sql = "SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE term_id = $latest_term->term_id AND taxonomy = '$taxonomy'";
						$new_term_taxonomy = $wpdb->get_row( $new_term_taxonomy_sql );

						$old_taxonomy_id_sql = "SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE term_id = $term->term_id AND taxonomy = '$taxonomy'";
						$old_taxonomy_id     = $wpdb->get_row( $old_taxonomy_id_sql );

						$update_term_relationships_with_new_taxonomy_id_sql = "UPDATE 
		                    $wpdb->term_relationships 
						SET term_taxonomy_id = $new_term_taxonomy->term_taxonomy_id 
						WHERE term_taxonomy_id = $old_taxonomy_id->term_taxonomy_id";
						$num_of_rows_updated                                = $wpdb->query( $update_term_relationships_with_new_taxonomy_id_sql );

						if ( $num_of_rows_updated > 1 ) {
							$wpdb->delete(
								$wpdb->term_taxonomy,
								[
									'term_taxonomy_id' => $old_taxonomy_id->term_taxonomy_id,
								]
							);
						}
					}
				}
			}

			$progress->tick();
		}
		$progress->finish();
	}

	/**
	 * Processes the Best of CPT XML that was provided by Bethesda.
	 *
	 * @param $args WP CLI positional arguments.
	 * @param $assoc_args WP CLI optional arguments.
	 */
	public function bethesda_migrate_best_of_content( $args, $assoc_args ) {
		$xml_path = get_home_path() . 'bethesdamagazine.WordPress.2022-06-09.xml';
		$xml      = new \DOMDocument();
		$xml->loadXML( file_get_contents( $xml_path ) );

		$rss = $xml->getElementsByTagName( 'rss' )->item( 0 );

		$best_of_term_id = category_exists( 'Best of' );
		if ( is_null( $best_of_term_id ) ) {
			$best_of_term_id = wp_create_category( 'Best of' );
		} else {
			$best_of_term_id = (int) $best_of_term_id;
		}

		$best_of_bethesda_term_id = category_exists( 'Best of Bethesda 2022', $best_of_term_id );
		if ( is_null( $best_of_bethesda_term_id ) ) {
			$best_of_bethesda_term_id = wp_create_category( 'Best of Bethesda 2022', $best_of_term_id );
		} else {
			$best_of_bethesda_term_id = (int) $best_of_bethesda_term_id;
		}

		/* @var DOMNodeList $channel_children */
		$channel_children = $rss->childNodes->item( 1 )->childNodes;

		$posts   = [];
		$authors = [];
		foreach ( $channel_children as $child ) {
			if ( 'wp:author' === $child->nodeName ) {
				$author = $this->handle_xml_author( $child );
				$authors[ $author->user_login ] = $author;
			} else if ( 'item' === $child->nodeName ) {
				$posts[] = $this->handle_xml_item( $child, $best_of_term_id, $authors );
			}
		}

		$this->process_posts( $posts, [ $best_of_term_id, $best_of_bethesda_term_id ] );
	}

	/**
	 * Processes and creates an array of posts created from an XML file.
	 *
	 * @param array $posts Array of post objects. {
	 *     @type array $categories
	 *     @type array $tags
	 *     @type array $post
	 * }
	 */
	public function process_posts( array $posts, array $merge_term_ids ) {
		$progress = WP_CLI\Utils\make_progress_bar( 'Processing posts...', count( $posts ) );

		foreach ( $posts as $post ) {
			$categories = $post['categories'];
			$tags = $post['tags'];
			$post = $post['post'];

			$post_id = wp_insert_post( $post );

			foreach ( $categories as &$category ) {
				$exists = category_exists( $category['cat_name'], $category['category_parent'] );

				if ( is_null( $exists ) ) {
					$result = wp_insert_category( $category );

					if ( is_int( $result ) && $result > 0 ) {
						$category = $result;
					}
				} else {
					$category = (int) $exists;
				}
			}

			$categories = array_merge( $categories, $merge_term_ids );

			wp_set_post_categories( $post_id, $categories );

			foreach ( $tags as &$tag ) {
				$exists = tag_exists( $tag );

				if ( is_null( $exists ) ) {
					$result = wp_insert_term( $tag, 'post_tag' );

					if ( ! ( $result instanceof \WP_Error ) ) {
						$tag = $result['term_id'];
					}
				}
			}

			wp_set_object_terms( $post_id, $tags, 'post_tag' );

			$progress->tick();
		}
		$progress->finish();
	}

	/**
	 * @param \DOMNode $author
	 *
	 * @return false|\WP_User
	 */
	private function handle_xml_author( \DOMNode $author ) {
		$author_data = [
			'user_login'   => '',
			'user_email'   => '',
			'display_name' => '',
			'first_name'   => '',
			'last_name'    => '',
			'role'         => 'author',
			'user_pass'    => wp_generate_password(),
		];

		foreach ( $author->childNodes as $node ) {
			/* @var \DOMNode $node */
			$nodeName = $node->nodeName;

			switch ( $nodeName ) {
				case 'wp:author_login':
					$author_data['user_login'] = $node->nodeValue;
					break;
				case 'wp:author_email':
					$author_data['user_email'] = $node->nodeValue;
					break;
				case 'wp:author_display_name':
					$author_data['display_name'] = $node->nodeValue;
					break;
				case 'wp:author_first_name':
					$author_data['first_name'] = $node->nodeValue;
					break;
				case 'wp:author_last_name':
					$author_data['last_name'] = $node->nodeValue;
					break;
			}
		}

		$user = get_user_by( 'login', $author_data['user_login'] );

		if ( false === $user ) {
			$user_id = wp_insert_user( $author_data );
			$user    = get_user_by( 'id', $user_id );
		}

		return $user;
	}

	/**
	 * Handles XML <item>'s from provided file to import as Posts.
	 *
	 * @param \DOMNode $item XML <item>.
	 * @param int $best_of_term_id Parent category ID.
	 * @param array $authors Recently imported authors.
	 *
	 * @return array
	 */
	private function handle_xml_item( \DOMNode $item, int $best_of_term_id = 0, array $authors = [] ) {
		$post                      = [
			'post_type'     => 'post',
			'meta_input'    => [],
		];
		$categories                = [];
		$tags                      = [];
		$post_content_template     = '{best_description}{best_link}{best_caption}';
		$best_description_template = '</p>{content}</p><br>';
		$best_link_template        = '<a href={url} target=_blank>{description}</a><br>';
		$best_caption_template     = '<p>{content}</p>';
		$best_description          = '';
		$best_link                 = '';
		$best_caption              = '';

		foreach ( $item->childNodes as $child ) {
			/* @var \DOMNode $child */
			if ( 'title' === $child->nodeName ) {
				$post['post_title'] = $child->nodeValue;
			}

			if ( 'dc:creator' === $child->nodeName ) {
				$post['post_author'] = $authors[ $child->nodeValue ]->ID ?? 0;
			}

			if ( 'link' === $child->nodeName ) {
				$post['guid'] = $child->nodeValue;
			}

			if ( 'wp:post_date' === $child->nodeName ) {
				$post['post_date'] = $child->nodeValue;
			}

			if ( 'wp:post_date_gmt' === $child->nodeName ) {
				$post['post_date_gmt'] = $child->nodeValue;
			}

			if ( 'wp:post_modified' === $child->nodeName ) {
				$post['post_modified'] = $child->nodeValue;
			}

			if ( 'wp:post_modified_gmt' === $child->nodeName ) {
				$post['post_modified_gmt'] = $child->nodeValue;
			}

			if ( 'wp:comment_status' === $child->nodeName ) {
				$post['comment_status'] = $child->nodeValue;
			}

			if ( 'wp:ping_status' === $child->nodeName ) {
				$post['ping_status'] = $child->nodeValue;
			}

			if ( 'wp:status' === $child->nodeName ) {
				$post['post_status'] = $child->nodeValue;
			}

			if ( 'wp:post_name' === $child->nodeName ) {
				$post['post_name'] = $child->nodeValue;
			}

			if ( 'wp:post_parent' === $child->nodeName ) {
				$post['post_parent'] = $child->nodeValue;
			}

			if ( 'wp:menu_order' === $child->nodeName ) {
				$post['menu_order'] = $child->nodeValue;
			}

			if ( 'wp:post_password' === $child->nodeName ) {
				$post['post_password'] = $child->nodeValue;
			}

			if ( 'wp:postmeta' === $child->nodeName ) {
				$meta_key   = $child->childNodes->item( 1 )->nodeValue;
				$meta_value = trim( $child->childNodes->item( 3 )->nodeValue );

				if ( empty( $meta_value ) || str_starts_with( $meta_value, 'field_' ) ) {
					continue;
				}

				switch ( $meta_key ) {
					case 'best_custom_title';
						$post['post_title'] = $meta_value;
						break;
					case 'best_selection':
						if ( 'editor' === $meta_value ) {
							$tags[] = "Editor's Pick";
						} else if ( 'reader' === $meta_value ) {
							$tags[] = "Reader's Pick";
						}
						break;
					case 'best_caption':
						$best_caption = strtr(
							$best_caption_template,
							[
								'{content}' => $meta_value,
							]
						);
						break;
					case 'best_link':
						$best_link = strtr(
							$best_link_template,
							[
								'{url}'         => $meta_value,
								'{description}' => '',
							]
						);
						break;
					case 'best_description':
						$best_description = strtr(
							$best_description_template,
							[
								'{content}' => $meta_value,
							]
						);
						break;
					case 'best_image':
						$post['meta_input']['_thumbnail_id'] = $meta_value;
						break;
				}
			}

			$post['post_content'] = strtr(
				$post_content_template,
				[
					'{best_description}' => $best_description,
					'{best_link}'        => $best_link,
					'{best_caption}'     => $best_caption,
				]
			);

			if ( 'category' === $child->nodeName ) {
				$categories[] = [
					'cat_name'          => htmlspecialchars_decode( $child->nodeValue ),
					'category_nicename' => $child->attributes->getNamedItem( 'nicename' )->nodeValue,
					'category_parent'   => $best_of_term_id,
				];
			}
		}

		return [
			'post'       => $post,
			'tags'       => $tags,
			'categories' => $categories,
		];
	}

	/**
	 * Clean author name from prefixes.
	 *
	 * @param string $author Author name to clean.
	 * @return string
	 */
	private function clean_author_name( $author ) {
		$prefixes = array(
			'By ',
			'By: ',
			'From Bethesda Now - By ',
			'Compiled By ',
		);

		foreach ( $prefixes as $prefix ) {
			if ( $this->str_starts_with( $author, $prefix ) ) {
				return preg_replace( '/^' . preg_quote( $prefix, '/' ) . '/i', '', $author );
			}
		}

		return $author;
	}

	/**
	 * Checks if a string starts with a given substring
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for in the haystack.
	 * @return boolean
	 */
	private function str_starts_with( $haystack, $needle ) {
		return substr( strtolower( $haystack ), 0, strlen( strtolower( $needle ) ) ) === strtolower( $needle );
	}

	/**
	 * Checks if a string ends with a given substring
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for in the haystack.
	 * @return boolean
	 */
	private function str_ends_with( $haystack, $needle ) {
		$length = strlen( $needle );
		if ( ! $length ) {
			return true;
		}
		return substr( strtolower( $haystack ), -$length ) === strtolower( $needle );
	}

	/**
	 * Determine if a string contains a given substring
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for in the haystack.
	 * @return boolean
	 */
	private function str_contains( $haystack, $needle ) {
		return strpos( strtolower( $haystack ), strtolower( $needle ) ) !== false;
	}

	/**
	 * Simple file logging.
	 *
	 * @param string  $file    File name or path.
	 * @param string  $message Log message.
	 * @param boolean $to_cli Display the logged message in CLI.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
