<?php
/**
 * Logic for working Taxonomies.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Logic;

use WP_CLI;

/**
 * Taxonomy implements common migration logic that are used to work with the Simple Local Avatars plugin
 */
class Taxonomy {

	/**
	 * Returns single or multiple Category data arrays depending on $where.
	 *
	 * @param array $where               Where columns from $supported_where_columns_and_tables.
	 * @param bool  $return_first_result If true, will return a one dimensional array which doesn't contain further subarrays.
	 *
	 * @throws \RuntimeException If wrong $where key is given.
	 *
	 * @return array {
	 *      Array with Category data subarrays, or if $return_first_result === true just these keys and values without subarray structure.
	 *
	 *      @type string term_id     Category term_id.
	 *      @type string taxonomy    Should always be 'category'.
	 *      @type string name        Category name.
	 *      @type string slug        Category slug.
	 *      @type string parent      Category parent term_id.
	 *      @type string description Category description.
	 *      @type string count       Category count.
	 * }
	 */
	public function get_categories_data( array $where, bool $return_first_result = false ): array {
		if ( empty( $where ) ) {
			return [];
		}

		global $wpdb;

		// Supported $where keys and the tables of these columns in DB.
		$supported_where_columns_and_tables = [
			'term_id'     => 't',
			'taxonomy'    => 'tt',
			'name'        => 't',
			'slug'        => 't',
			'parent'      => 'tt',
			'description' => 'tt',
		];

		// Compose the AND clause.
		$and_clause = '';
		$and_values = [];
		foreach ( $where as $where_column => $where_value ) {

			// Check if where column is supported.
			if ( ! isset( $supported_where_columns_and_tables[ $where_column ] ) ) {
				throw new \RuntimeException( sprintf( 'Where column %s not supported.', $where_column ) );
			}

			// Compose the AND clause.
			$where_column_escaped = esc_sql( $where_column );
			$where_table          = $supported_where_columns_and_tables[ $where_column ];
			$and_clause          .= ' AND ' . $where_table . '.' . $where_column_escaped . ' = %s ';
			$and_values[]         = $where_value;
		}

		// Limit clause.
		$limit_clause = '';
		if ( true === $return_first_result ) {
			$limit_clause = ' LIMIT 1 ';
		}

		// phpcs:disable -- $and_clause and $limit_clause are sanitized.
		$categories = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy, t.name, t.slug, tt.parent, tt.description, tt.count
				FROM {$wpdb->terms} t
		        JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = 'category' " .
				$and_clause .
				$limit_clause,
				$and_values
			),
			ARRAY_A
		);
		// phpcs:enable

		// Return just the first element.
		if ( true === $return_first_result ) {
			return $categories[0] ?? [];
		}

		return $categories;
	}

	/**
	 * Fetches a full category tree with all the children categories down to the end ones.
	 *
	 * @param array $category {
	 *   Category data array.
	 *
	 *     @type string term_id     Category term_id.
	 *     @type string taxonomy    Should always be 'category'.
	 *     @type string name        Category name.
	 *     @type string slug        Category slug.
	 *     @type string parent      Category parent term_id.
	 *     @type string description Category description.
	 *     @type string count       Category count.
	 * }
	 *
	 * @return array {
	 *     A nested array of subarray categories.
	 *
	 *     @type string term_id     Category term_id.
	 *     @type string taxonomy    Should always be 'category'.
	 *     @type string name        Category name.
	 *     @type string slug        Category slug.
	 *     @type string parent      Parent ID.
	 *     @type string description Category description.
	 *     @type string count       Category count.
	 *     @type array  children    Array with children categories with same keys-values as this array.
	 * }
	 */
	public function get_category_tree_data( array $category ): array {
		$category_tree             = $category;
		$category_tree['children'] = [];

		$children_categories = $this->get_categories_data( [ 'parent' => $category['term_id'] ] );
		if ( ! empty( $children_categories ) ) {
			foreach ( $children_categories as $child_category ) {
				$category_tree['children'][] = $this->get_category_tree_data( $child_category );
			}
		}

		return $category_tree;
	}

	/**
	 * Uproots and permanently relocates the whole category tree under a new parent.
	 *
	 * @param array $category_tree_data {
	 *     A nested array of subarray categories, same as the return of self::get_category_tree_data().
	 *
	 *     @type string term_id     Category term_id.
	 *     @type string taxonomy    Should always be 'category'.
	 *     @type string name        Category name.
	 *     @type string slug        Category slug.
	 *     @type string parent      Parent ID.
	 *     @type string description Category description.
	 *     @type string count       Category count.
	 *     @type array  children    Array with children categories with same keys-values as this array.
	 * }
	 * @param int   $parent_term_id Parent term_id.
	 *
	 * @throws \RuntimeException If a category slug was not updated successfully.
	 *
	 * @return void
	 */
	public function replant_category_tree( $category_tree_data, $parent_term_id ) {

		// Get this category if it already exists.
		$existing_category_data = $this->get_categories_data(
			[
				'name'        => $category_tree_data['name'],
				'description' => $category_tree_data['description'],
				'parent'      => $parent_term_id,
			],
			true
		);

		// Create if doesn't exist.
		if ( ! empty( $existing_category_data ) ) {
			$replanted_category_term_id = $existing_category_data['term_id'];
		} else {
			// Change this slug to free up the slug, so that the newly created category has a nice version of it.
			$updated = wp_update_term( $category_tree_data['term_id'], 'category', [ 'slug' => $category_tree_data['slug'] . '_x' ] );
			if ( is_wp_error( $updated ) ) {
				throw new \RuntimeException( sprintf( 'Error when changing category %d slug from %s to %s', $category_tree_data['term_id'], $category_tree_data['slug'], $category_tree_data['slug'] . '_old' ) );
			}

			// Recreate category.
			$replanted_category_term_id = $this->create_category( $category_tree_data, $parent_term_id );
		}

		// Reassign all posts from original category to new category.
		$this->reassign_all_content_from_one_category_to_another( $category_tree_data['term_id'], $replanted_category_term_id );

		// Replant children categories recursively.
		foreach ( $category_tree_data['children'] as $category_child_tree_data ) {
			$this->replant_category_tree( $category_child_tree_data, $replanted_category_term_id );
		}
	}

	/**
	 * Fixes counts for taxonomy.
	 *
	 * @param string $taxonomy Taxonomy, e.g. 'category'.
	 *
	 * @return void
	 */
	public function fix_taxonomy_term_counts( string $taxonomy ) {
		$get_terms_args = [
			'taxonomy'   => $taxonomy,
			'fields'     => 'ids',
			'hide_empty' => false,
		];

		$update_term_ids = get_terms( $get_terms_args );
		foreach ( $update_term_ids as $key_term_id => $term_id ) {
			wp_update_term_count_now( [ $term_id ], $taxonomy );
		}

		wp_cache_flush();
	}

	/**
	 * Deletes the category together with all its children categories.
	 *
	 * @param array $category_tree_data {
	 *     A nested array of subarray categories, same as the return of self::get_category_tree_data().
	 *
	 *     @type string term_id     Category term_id.
	 *     @type string taxonomy    Should always be 'category'.
	 *     @type string name        Category name.
	 *     @type string slug        Category slug.
	 *     @type string parent      Parent ID.
	 *     @type string description Category description.
	 *     @type string count       Category count.
	 *     @type array  children    Array with children categories with same keys-values as this array.
	 * }
	 *
	 * @return void
	 */
	public function delete_category_tree( array $category_tree_data ): void {

		wp_delete_category( $category_tree_data['term_id'] );

		foreach ( $category_tree_data['children'] as $child_category_tree_data ) {
			$this->delete_category_tree( $child_category_tree_data );
		}
	}

	/**
	 * Reassigns all content from one category to a different category.
	 *
	 * @param int $source_term_id      Source term_id.
	 * @param int $destination_term_id Destination term_id.
	 *
	 * @return void
	 */
	public function reassign_all_content_from_one_category_to_another( int $source_term_id, int $destination_term_id ): void {
		$source_term_taxonomy_id      = $this->get_term_taxonomy_id_by_term_id( $source_term_id );
		$destination_term_taxonomy_id = $this->get_term_taxonomy_id_by_term_id( $destination_term_id );

		$this->update_object_relational_mapping_term_taxonomy_id( $source_term_taxonomy_id, $destination_term_taxonomy_id );
	}

	/**
	 * Gets term_taxonomy_id of a term_id.
	 *
	 * @param int $term_id Term_id.
	 *
	 * @return string|null Return from $wpdb::get_var().
	 */
	public function get_term_taxonomy_id_by_term_id( $term_id ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d;", $term_id ) );
	}

	/**
	 * Runs a direct DB UPDATE on wp_term_relationships table and updates term_taxonomy_id from one value to a different one.
	 *
	 * @param int $old_term_taxonomy_id Old term_taxonomy_id.
	 * @param int $new_term_taxonomy_id New term_taxonomy_id.
	 *
	 * @return string|null Return from $wpdb::get_var().
	 */
	public function update_object_relational_mapping_term_taxonomy_id( $old_term_taxonomy_id, $new_term_taxonomy_id ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "UPDATE {$wpdb->term_relationships} SET term_taxonomy_id = %d WHERE term_taxonomy_id = %d ;", $new_term_taxonomy_id, $old_term_taxonomy_id ) );
	}

	/**
	 * Creates the given category (but not its children) under a given parent ID.
	 *
	 * @param array $category_tree_data {
	 *     A nested array of subarray categories, same as the return of self::get_category_tree_data().
	 *
	 *     @type string term_id     Category term_id.
	 *     @type string taxonomy    Should always be 'category'.
	 *     @type string name        Category name.
	 *     @type string slug        Category slug.
	 *     @type string parent      Parent ID.
	 *     @type string description Category description.
	 *     @type string count       Category count.
	 *     @type array  children    Array with children categories with same keys-values as this array.
	 * }
	 * @param int   $parent_term_id     Parent ID under which the category is created.
	 *
	 * @return int|\WP_Error Return from \wp_insert_category().
	 */
	public function create_category( $category_tree_data, $parent_term_id ) {

		// \wp_insert_category() returns created term_id, or zero if the category exists.
		$term_id_if_new_or_zero = wp_insert_category(
			[
				'cat_name'             => $category_tree_data['name'],
				'category_description' => $category_tree_data['description'],
				'category_parent'      => $parent_term_id,
			]
		);

		return $term_id_if_new_or_zero;
	}
}
