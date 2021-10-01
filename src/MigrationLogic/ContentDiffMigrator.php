<?php

namespace NewspackCustomContentMigrator\MigrationLogic;

use \WP_CLI;
use \WP_User;

class ContentDiffMigrator {

	// Data array keys.
	const DATAKEY_POST = 'post';
	const DATAKEY_POSTMETA = 'postmeta';
	const DATAKEY_COMMENTS = 'comment';
	const DATAKEY_COMMENTMETA = 'commentmeta';
	const DATAKEY_USERS = 'users';
	const DATAKEY_USERMETA = 'usermeta';
	const DATAKEY_TERMRELATIONSHIPS = 'term_relationships';
	const DATAKEY_TERMTAXONOMY = 'term_taxonomy';
	const DATAKEY_TERMS = 'terms';
	const DATAKEY_TERMMETA = 'termmeta';

	/**
	 * @var object Global $wpdb.
	 */
	private $wpdb;

	/**
	 * ContentDiffMigrator constructor.
	 *
	 * @param object $wpdb Global $wpdb.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Gets a diff of new Posts and Pages from the Live Site.
	 *
	 * @param string $live_table_prefix Table prefix for the Live Site.
	 *
	 * @return array Result from $wpdb->get_results.
	 */
	public function get_live_diff_content_ids( $live_table_prefix ) {

		// TODO check if live tables not found

		$live_posts_table = esc_sql( $live_table_prefix ) . 'posts';
		$posts_table = $this->wpdb->prefix . 'posts';
		$sql = "SELECT lwp.ID FROM {$live_posts_table} lwp
			LEFT JOIN {$posts_table} wp
				ON wp.post_name = lwp.post_name
				AND wp.post_title = lwp.post_title
				AND wp.post_status = lwp.post_status
				AND wp.post_date = lwp.post_date
			WHERE lwp.post_type IN ( 'post', 'page' )
			AND wp.ID IS NULL;";
		$results = $this->wpdb->get_results( $sql, ARRAY_A );

		$ids = [];
		foreach ( $results as $result ) {
			$ids[] = $result[ 'ID' ];
		}

		return $ids;
	}

	/**
	 * Fetches a Post and all core WP relational objects belonging to the post. Can fetch from a custom table prefix.
	 *
	 * @param int    $post_id           Post ID.
	 * @param string $live_table_prefix Table prefix to fetch from.
	 *
	 * @return array $args {
	 *     Post and all core WP Post-related data.
	 *
	 *     @type array self::DATAKEY_POST              Contains `posts` rows.
	 *     @type array self::DATAKEY_POSTMETA          `postmeta` rows.
	 *     @type array self::DATAKEY_COMMENTS          `comments` rows.
	 *     @type array self::DATAKEY_COMMENTMETA       `commentmeta` rows.
	 *     @type array self::DATAKEY_USERS             `users` rows (for the Post Author, and the Comment Users).
	 *     @type array self::DATAKEY_USERMETA          `usermeta` rows.
	 *     @type array self::DATAKEY_TERMRELATIONSHIPS `term_relationships` rows.
	 *     @type array self::DATAKEY_TERMTAXONOMY      `term_taxonomy` rows.
	 *     @type array self::DATAKEY_TERMS             `terms` rows.
	 * }
	 */
	public function get_data( $post_id, $table_prefix ) {

		// TODO check if live tables not found

		$data = $this->get_empty_data_array();

		// Get Post.
		$post_row = $this->select_post_row( $table_prefix, $post_id );
		$data[ self::DATAKEY_POST ] = $post_row;

		// Get Post Metas.
		$data[ self::DATAKEY_POSTMETA ] = $this->select_postmeta_rows( $table_prefix, $post_id );

		// Get Post Author User.
		$author_row = $this->select_user_row( $table_prefix, $data[ self::DATAKEY_POST ][ 'post_author' ] );
		$data[ self::DATAKEY_USERS ][] = $author_row;

		// Get Post Author User Metas.
		$data[ self::DATAKEY_USERMETA ] = array_merge(
			$data[ self::DATAKEY_USERMETA ],
			$this->select_usermeta_rows( $table_prefix, $author_row[ 'ID' ] )
		);

		// Get Comments.
		if ( $post_row[ 'comment_count' ] > 0 ) {
			$comment_rows = $this->select_comment_rows( $table_prefix, $post_id );
			$data[ self::DATAKEY_COMMENTS ] = array_merge(
				$data[ self::DATAKEY_COMMENTS ],
				$comment_rows
			);

			// Get Comment Metas.
			foreach ( $comment_rows as $key_comment => $comment ) {
				$data[ self::DATAKEY_COMMENTMETA ] = array_merge(
					$data[ self::DATAKEY_COMMENTMETA ],
					$this->select_commentmeta_rows( $table_prefix, $comment[ 'comment_ID' ] )
				);

				// Get Comment User if not already fetched.
				if ( $comment[ 'user_id' ] > 0 && empty( $this->filter_array_elements( $data[ self::DATAKEY_USERS ], 'ID', $comment[ 'user_id' ] ) ) ) {
					$comment_user_row = $this->select_user_row( $table_prefix, $comment[ 'user_id' ] );
					$data[ self::DATAKEY_USERS ][] = $comment_user_row;

					// Get Get Comment User Metas.
					$data[ self::DATAKEY_USERMETA ] = array_merge(
						$data[ self::DATAKEY_USERMETA ],
						$this->select_usermeta_rows( $table_prefix, $comment_user_row[ 'ID' ] )
					);
				}
			}
		}

		// Get Term Relationships.
		$term_relationships_rows = $this->select_term_relationships_rows( $table_prefix, $post_id );
		$data[ self::DATAKEY_TERMRELATIONSHIPS ] = array_merge(
			$data[ self::DATAKEY_TERMRELATIONSHIPS ],
			$term_relationships_rows
		);

		// Get Term Taxonomies.
		foreach ( $term_relationships_rows as $term_relationship_row ) {
			$term_taxonomy_id = $term_relationship_row[ 'term_taxonomy_id' ];
			$term_taxonomy = $this->select_term_taxonomy_row( $table_prefix, $term_taxonomy_id );
			$data[ self::DATAKEY_TERMTAXONOMY ][] = $term_taxonomy;

			// Get Terms.
			$term_id = $term_taxonomy[ 'term_id' ];
			$data[ self::DATAKEY_TERMS ][] = $this->select_terms_row( $table_prefix, $term_id );
		}

		// Get Term Metas.
		foreach ( $data[ self::DATAKEY_TERMS ] as $term_row ) {
			$data[ self::DATAKEY_TERMMETA ] = array_merge(
				$this->select_termmeta_rows( $table_prefix, $term_row[ 'term_id' ] ),
				$data[ self::DATAKEY_TERMMETA ]
			);
		}

		return $data;
	}

	/**
	 * Imports all the Post data.
	 *
	 * @param array $data Array containing all the data, @see ContentDiffMigrator::get_data for structure.
	 *
	 * @return int Imported Post ID.
	 */
	public function import_data( $data ) {
		// Insert Post and Post Metas.
		$post_id = $this->insert_post( $data[ self::DATAKEY_POST ] );
		$this->insert_postmeta( $data[ self::DATAKEY_POSTMETA ], $post_id );

		// Use existing or insert Author User.
		$author_row_id = $data[ self::DATAKEY_POST ][ 'post_author' ];
		$author_row = $this->filter_array_element( $data[ self::DATAKEY_USERS ], 'ID', $author_row_id );
		$author_id = $this->get_existing_user_or_insert_new( $author_row, $data );

		// Update inserted Post's Author.
		$this->update_post_author( $post_id, $author_id );

		// Insert Comments.
		$comment_ids_updates = [];
		foreach ( $data[ self::DATAKEY_COMMENTS ] as $comment_row ) {
			$comment_id_old = $comment_row[ 'comment_ID' ];

			// First insert the Comment User, or get the existing one.
			$comment_user_row_id = $comment_row[ 'user_id' ];
			if ( 0 == $comment_user_row_id ) {
				$comment_user_id = 0;
			} else {
				$comment_user_row = $this->filter_array_element( $data[ self::DATAKEY_USERS ], 'ID', $comment_user_row_id );
				$comment_user_id = $this->get_existing_user_or_insert_new( $comment_user_row, $data );
			}

			// Insert Comment and Comment Metas.
			$comment_id = $this->insert_comment( $comment_row, $post_id, $comment_user_id );
			$comment_ids_updates[ $comment_id_old ] = $comment_id;
			$commentmeta_rows = $this->filter_array_elements( $data[ self::DATAKEY_COMMENTMETA ], 'comment_id' , $comment_row[ 'comment_ID' ] );
			$this->insert_commentmetas( $commentmeta_rows, $comment_id );
		}

		// Loop through all comments, and update their Parent IDs.
		foreach ( $comment_ids_updates as $comment_id_old => $comment_id_new ) {
			// $comment_row = $this->select_comment_row( $this->wpdb->table_prefix, $comment_id_new );
			$comment_row = $this->filter_array_element( $data[ self::DATAKEY_COMMENTS ], 'comment_ID', $comment_id_old );
			$comment_parent_old = $comment_row[ 'comment_parent' ];
			$comment_parent_new = $comment_ids_updates[ $comment_parent_old ] ?? null;
			if ( $comment_parent_old > 0 && $comment_parent_new ) {
				$this->update_comment_parent( $comment_id_new, $comment_parent_new );
			}
		}

		// Insert Terms.
		$terms_ids_updates = [];
		$term_taxonomy_ids_updates = [];
		foreach ( $data[ self::DATAKEY_TERMS ] as $term_row ) {

			$term_id_existing = $this->term_exists( $term_row[ 'name' ], '', null );
			if ( null == $term_id_existing ) {
				$term_id = $this->insert_term( $term_row );
			} else {
				$term_id = $term_id_existing;
			}
			$terms_ids_updates[ $term_row[ 'term_id' ] ] = $term_id;

			// Insert Term Taxonomy records.
			/*
			 * A Term can be shared by multiple Taxonomies in WP (e.g. Term "blue" by Taxonomies "category" and "color").
			 * That's why instead of simply looping through all Term Taxonomies and inserting them, we're inserting each Term's
			 * Term Taxonomies at this point.
			 */
			$term_taxonomy_rows = $this->filter_array_elements( $data[ self::DATAKEY_TERMTAXONOMY ], 'term_id', $term_row[ 'term_id' ] );
			foreach ( $term_taxonomy_rows as $term_taxonomy_row ) {
				$term_taxonomy_id = $this->get_term_taxonomy_or_insert_new(
					$term_row[ 'name' ],
					$term_row[ 'slug' ],
					$term_taxonomy_row[ 'taxonomy' ],
					$term_taxonomy_row,
					$term_id
				);
				$term_taxonomy_ids_updates[ $term_taxonomy_row[ 'term_taxonomy_id' ] ] = $term_taxonomy_id;
			}
		}

		// Insert Term Relationships.
		foreach ( $data[ self::DATAKEY_TERMRELATIONSHIPS ] as $term_relationship_row ) {
			$term_taxonomy_id_old = $term_relationship_row[ 'term_taxonomy_id' ];
			$term_taxonomy_id_new = $term_taxonomy_ids_updates[ $term_taxonomy_id_old ] ?? null;
			if ( ! is_null( $term_taxonomy_id_new ) ) {
				$this->insert_term_relationship( $post_id, $term_taxonomy_id_new );
			} else {
				// TODO missing record, but this shouldn't happen.
			}
		}

		return $post_id;
	}

	/**
	 * Loops through imported posts and updates their parents IDs.
	 *
	 * @param array $imported_post_ids Keys are IDs on Live Site, values are IDs of imported posts on Local Site.
	 */
	public function update_post_parent( $post_id, $imported_post_ids ) {
		$post = get_post( $post_id );
		$new_parent_id = $imported_post_ids[ $post->post_parent ] ?? null;
		if ( $post->post_parent > 0 && $new_parent_id ) {
			$this->wpdb->update( $this->wpdb->posts, [ 'post_parent' => $new_parent_id ], [ 'ID' => $post->ID ] );
		}
	}

	/**
	 * Returns an empty data array.
	 *
	 * @return array $args Empty data array for ContentDiffMigrator::get_data. @see ContentDiffMigrator::get_data for structure.
	 */
	private function get_empty_data_array() {
		return [
			self::DATAKEY_POST => [],
			self::DATAKEY_POSTMETA => [],
			self::DATAKEY_COMMENTS => [],
			self::DATAKEY_COMMENTMETA => [],
			self::DATAKEY_USERS => [],
			self::DATAKEY_USERMETA => [],
			self::DATAKEY_TERMRELATIONSHIPS => [],
			self::DATAKEY_TERMTAXONOMY => [],
			self::DATAKEY_TERMS => [],
			self::DATAKEY_TERMMETA => [],
		];
	}

	/**
	 * Checks if a Term Taxonomy exists.
	 *
	 * @param string $term_name Term name.
	 * @param string $term_slug Term slug.
	 * @param string $taxonomy  Taxonomy
	 *
	 * @return string|null term_taxonomy_id or null.
	 */
	public function get_existing_term_taxonomy( $term_name, $term_slug, $taxonomy ) {
		return $this->wpdb->get_var( $this->wpdb->prepare(
			"SELECT tt.term_taxonomy_id
			FROM {$this->wpdb->term_taxonomy} tt
			JOIN {$this->wpdb->terms} t
		        ON tt.term_id = t.term_id
			WHERE t.name = %s
			AND t.slug = %s
		    AND tt.taxonomy = %s;",
			$term_name,
			$term_slug,
			$taxonomy
		) );
	}

	/**
	 * Gets an existing termtaxonomy ID, or inserts a new one.
	 *
	 * @param string $term_name         Term name.
	 * @param string $term_slug         Term slug.
	 * @param string $taxonomy          Taxonomy.
	 * @param array  $term_taxonomy_row termtaxonomy $data row to be inserted, if one doesn't exist.
	 * @param int    $term_id           Term ID to be used for the newly inserted termtaxonomy row.
	 *
	 * @return int term_taxonomy_id.
	 */
	public function get_term_taxonomy_or_insert_new( $term_name, $term_slug, $taxonomy, $term_taxonomy_row, $term_id ) {
		$term_taxonomy_id_existing = $this->get_existing_term_taxonomy( $term_name, $term_slug, $taxonomy );
		if ( $term_taxonomy_id_existing ) {
			$term_taxonomy_id = $term_taxonomy_id_existing;
		} else {
			$term_taxonomy_id = $this->insert_term_taxonomy( $term_taxonomy_row, $term_id );
		}

		return $term_taxonomy_id;
	}

	public function select_post_row( $table_prefix, $post_id ) {
		$post_row = $this->select( $table_prefix . 'posts', [ 'ID' => $post_id ], $select_just_one_row = true );
		if ( empty( $post_row ) ) {
			// TODO empty
		}

		return $post_row;
	}

	public function select_postmeta_rows( $table_prefix, $post_id ) {
		return $this->select( $table_prefix . 'postmeta', [ 'post_id' => $post_id ] );
	}

	public function select_user_row( $table_prefix, $author_id ) {
		return $this->select( $table_prefix . 'users', [ 'ID' => $author_id ], $select_just_one_row = true );
	}

	public function select_usermeta_rows( $table_prefix, $author_id ) {
		return $this->select( $table_prefix . 'usermeta', [ 'user_id' => $author_id ] );
	}

	public function select_comment_rows( $table_prefix, $post_id ) {
		return $this->select( $table_prefix . 'comments', [ 'comment_post_ID' => $post_id ] );
	}

	public function select_comment_row( $table_prefix, $comment_ID ) {
		return $this->select( $table_prefix . 'comments', [ 'comment_ID' => $comment_ID ], $select_just_one_row = true );
	}

	public function select_commentmeta_rows( $table_prefix, $comment_id ) {
		return $this->select( $table_prefix . 'commentmeta', [ 'comment_id' => $comment_id ] );
	}

	public function select_term_relationships_rows( $table_prefix, $post_id ) {
		return $this->select( $table_prefix . 'term_relationships', [ 'object_id' => $post_id ] );
	}

	public function select_term_taxonomy_row( $table_prefix, $term_taxonomy_id ) {
		return $this->select( $table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => $term_taxonomy_id ], $select_just_one_row = true );
	}

	public function select_terms_row( $table_prefix, $term_id ) {
		return $this->select( $table_prefix . 'terms', [ 'term_id' => $term_id ], $select_just_one_row = true );
	}

	public function select_termmeta_rows( $table_prefix, $term_id ) {
		return $this->select( $table_prefix . 'termmeta', [ 'term_id' => $term_id ] );
	}

	/**
	 * Simple select query with custom `where` conditions.
	 *
	 * @param string $table_name          Table name to select from.
	 * @param array  $where_conditions    Keys are columns, values are their values.
	 * @param bool   $select_just_one_row Select just one row. Default is false.
	 *
	 * @return array|void|null Result from $wpdb->get_results, or from $wpdb->get_row if $select_just_one_row is set to true.
	 */
	private function select( $table_name, $where_conditions, $select_just_one_row = false ) {
		$sql = 'SELECT * FROM ' . esc_sql( $table_name );

		if ( ! empty( $where_conditions ) ) {
			$where_sprintf = '';
			foreach ( $where_conditions as $column => $value ) {
				$where_sprintf .= ( ! empty( $where_sprintf ) ? ' AND' : '' )
				                  . ' ' . esc_sql( $column ) . ' = %s';
			}
			$where_sprintf = ' WHERE' . $where_sprintf;
			$sql_sprintf = $sql . $where_sprintf;

			$sql = $this->wpdb->prepare( $sql_sprintf, array_values( $where_conditions ) );
		}

		if ( true === $select_just_one_row ) {
			return $this->wpdb->get_row( $sql, ARRAY_A );
		} else {
			return $this->wpdb->get_results( $sql, ARRAY_A );
		}
	}

	/**
	 * Inserts Post.
	 *
	 * @param array $post_row `post` row.
	 *
	 * @return int Inserted Post ID.
	 */
	public function insert_post( $post_row ) {
		unset( $post_row['ID'] );

		$inserted = $this->wpdb->insert( $this->wpdb->posts, $post_row );
		if ( 1 != $inserted ) {
			// TODO error
		}
		$post_id = $this->wpdb->insert_id;

		return $post_id;
	}

	/**
	 * @param array $postmeta_rows
	 * @param int $post_id
	 *
	 * @return array Array of inserted `meta_id`s.
	 */
	public function insert_postmeta( $postmeta_rows, $post_id ) {
		$postmeta_ids = [];
		foreach ( $postmeta_rows as $postmeta_row ) {

			unset( $postmeta_row[ 'meta_id' ] );
			$postmeta_row[ 'post_id' ] = $post_id;

			$inserted = $this->wpdb->insert( $this->wpdb->postmeta, $postmeta_row );
			if ( 1 != $inserted ) {
				// TODO error
			}
			$postmeta_ids[] = $this->wpdb->insert_id;
		}

		return $postmeta_ids;
	}

	/**
	 * Inserts a User.
	 *
	 * @param array $user_row      `user` row.
	 *
	 * @return int Inserted User ID.
	 */
	public function insert_user( $user_row ) {
		unset( $user_row[ 'ID' ] );

		$inserted = $this->wpdb->insert( $this->wpdb->users, $user_row );
		if ( 1 != $inserted ) {
			// TODO error
		}
		$user_id = $this->wpdb->insert_id;

		return $user_id;
	}

	/**
	 * Inserts User Meta.
	 *
	 * @param array $usermeta_rows `usermeta` rows.
	 * @param int   $user_id       User ID.
	 *
	 * @return int Inserted User ID.
	 */
	public function insert_usermeta( $usermeta_rows, $user_id ) {
		$meta_ids = [];
		// Insert User Metas.
		foreach ( $usermeta_rows as $usermeta_row ) {
			unset( $usermeta_row[ 'umeta_id' ] );
			$usermeta_row[ 'user_id' ] = $user_id;

			$inserted = $this->wpdb->insert( $this->wpdb->usermeta, $usermeta_row );
			if ( 1 != $inserted ) {
				// TODO error
			}

			$meta_ids[] = $this->wpdb->insert_id;
		}

		return $meta_ids;
	}

	/**
	 * Inserts a Comment with an updated post_id and user_id.
	 *
	 * @param array $comment_row      `comment` row.
	 * @param int   $new_post_id      Post ID.
	 * @param int   $new_user_id      User ID.
	 *
	 * @return int Inserted comment_id.
	 */
	public function insert_comment( $comment_row, $new_post_id, $new_user_id ) {
		unset( $comment_row[ 'comment_ID' ] );
		$comment_row[ 'comment_post_ID' ] = $new_post_id;
		$comment_row[ 'user_id' ] = $new_user_id;

		$inserted = $this->wpdb->insert( $this->wpdb->comments, $comment_row );
		if ( 1 != $inserted ) {
			// TODO error
		}
		$comment_id = $this->wpdb->insert_id;

		return $comment_id;
	}

	/**
	 * Inserts Comment Metas with an updated comment_id.
	 *
	 * @param array $commentmeta_rows Comment Meta rows.
	 * @param int   $new_comment_id   New Comment ID.
	 *
	 * @return array
	 */
	public function insert_commentmetas( $commentmeta_rows, $new_comment_id ) {
		$meta_ids = [];
		foreach ( $commentmeta_rows as $commentmeta_row ) {
			unset( $commentmeta_row[ 'meta_id' ] );
			$commentmeta_row[ 'comment_id' ] = $new_comment_id;

			$inserted = $this->wpdb->insert( $this->wpdb->commentmeta, $commentmeta_row );
			if ( 1 != $inserted ) {
				// TODO error
			}

			$meta_ids[] = $this->wpdb->insert_id;
		}

		return $meta_ids;
	}

	/**
	 * Updates a Comment's parent ID.
	 *
	 * @param int $comment_id         Comment ID.
	 * @param int $comment_parent_new new Comment Parent ID.
	 *
	 * @return int|false Return from $wpdb::update -- the number of rows updated, or false on error.
	 */
	public function update_comment_parent( $comment_id, $comment_parent_new ) {
		return $this->wpdb->update( $this->wpdb->comments, [ 'comment_parent' => $comment_parent_new ], [ 'comment_ID' => $comment_id ] );
	}

	/**
	 * Inserts into `terms` table.
	 *
	 * @param array $term_row `term` row.
	 *
	 * @return int Inserted term_id.
	 */
	public function insert_term( $term_row ) {
		unset( $term_row[ 'term_id' ] );

		$inserted = $this->wpdb->insert( $this->wpdb->terms, $term_row );
		if ( 1 != $inserted ) {
			// TODO error
		}
		$term_id = $this->wpdb->insert_id;

		return $term_id;
	}

	/**
	 * Inserts into `term_taxonomy` table.
	 *
	 * @param array $term_taxonomy_row `term_taxonomy` row.
	 * @param int   $new_term_id       New `term_id` value to be set.
	 *
	 * @return int Inserted term_taxonomy_id.
	 */
	public function insert_term_taxonomy( $term_taxonomy_row, $new_term_id ) {
		unset( $term_taxonomy_row[ 'term_taxonomy_id' ] );
		$term_taxonomy_row[ 'term_id' ] = $new_term_id;

		$inserted = $this->wpdb->insert( $this->wpdb->term_taxonomy, $term_taxonomy_row );
		if ( 1 != $inserted ) {
			// TODO error
		}
		$term_taxonomy_id = $this->wpdb->insert_id;

		return $term_taxonomy_id;
	}

	/**
	 * Inserts into `term_relationships` table.
	 *
	 * @param int $object_id        `object_id` column.
	 * @param int $term_taxonomy_id `term_taxonomy_id` column.
	 *
	 * @return int|false Return from $wpdb::insert, the number of rows inserted, or false on error.
	 */
	public function insert_term_relationship( $object_id, $term_taxonomy_id ) {
		if ( $object_id || ! $term_taxonomy_id ) {
			// TODO shouldn't happen
		}

		$inserted = $this->wpdb->insert(
			$this->wpdb->term_relationships,
			[
				'object_id' => $object_id,
				'term_taxonomy_id' => $term_taxonomy_id,
			]
		);
		if ( 1 != $inserted ) {
			// TODO error
		}

		return $inserted;
	}

	/**
	 * Updates a Post's Author.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $new_author_id New Author ID.
	 *
	 * @return int|false Return from $wpdb::update -- the number of rows updated, or false on error.
	 */
	public function update_post_author( $post_id, $new_author_id ) {
		return $this->wpdb->update( $this->wpdb->posts, [ 'post_author' => $new_author_id ], [ 'ID' => $post_id ] );
	}

	/**
	 * Gets existing User ID from the database, or inserts a new user into the DB from the $user_row and $data.
	 *
	 * @param array $user_row User row.
	 * @param array $data     Data array, @see ContentDiffMigrator::get_data for structure.
	 *
	 * @return int User ID.
	 */
	public function get_existing_user_or_insert_new( $user_row, $data ) {
		$user_existing = $this->get_user_by( 'user_login', $user_row[ 'user_login' ] );
		if ( $user_existing instanceof WP_User ) {
			$user_id = $user_existing->ID;
		} else {
			$usermeta_rows = $this->filter_array_elements( $data[ self::DATAKEY_USERMETA ], 'user_id', $user_row[ 'ID' ] );
			$user_id = $this->insert_user( $user_row );
			$this->insert_usermeta( $usermeta_rows, $user_id );
		}

		return $user_id;
	}

	/**
	 * Wrapper for WP's native \get_user_by().
	 *
	 * @param string     $field The field to retrieve the user with. id | ID | slug | email | login.
	 * @param int|string $value A value for $field. A user ID, slug, email address, or login name.
	 *
	 * @return WP_User|false WP_User object on success, false on failure.
	 */
	public function get_user_by( $field, $value ) {
		return get_user_by( $field, $value );
	}

	/**
	 * Wrapper for WP's native \term_exists().
	 *
	 * @param int|string $term     The term to check. Accepts term ID, slug, or name..
	 * @param string     $taxonomy Optional. The taxonomy name to use.
	 * @param int        $parent   Optional. ID of parent term under which to confine the exists search.
	 *
	 * @return mixed @see term_exists.
	 */
	public function term_exists( $term, $taxonomy = '', $parent = null ) {
		return term_exists( $term, $taxonomy, $parent );
	}

	/**
	 * Filters a multidimensional array and searches for a subarray with a key and value.
	 *
	 * @param mixed $key   Array key to search for.
	 * @param mixed $value Array value to search for
	 *
	 * @return null|mixed
	 */
	public function filter_array_element( $array, $key, $value ) {
		foreach ( $array as $subarray ) {
			if ( isset( $subarray[ $key ] ) && $value == $subarray[ $key ] ) {
				return $subarray;
			}
		}

		// TODO throw exception

		return null;
	}

	/**
	 * Filters a multidimensional array and searches for all subarray elemens containing a key and value.
	 *
	 * @param mixed $key   Array key to search for.
	 * @param mixed $value Array value to search for
	 *
	 * @return array
	 */
	public function filter_array_elements( $array, $key, $value ) {
		$found = [];
		foreach ( $array as $subarray ) {
			if ( isset( $subarray[ $key ] ) && $value == $subarray[ $key ] ) {
				$found[] = $subarray;
			}
		}

		return $found;
	}
}
