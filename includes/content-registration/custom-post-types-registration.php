<?php
/**
 * Registers custom content types and custom fields.
 *
 * @package AtlasContentModeler
 */

declare(strict_types=1);

namespace WPE\AtlasContentModeler\ContentRegistration;

use InvalidArgumentException;
use WPGraphQL\Data\Connection\PostObjectConnectionResolver;
use WPGraphQL\Model\Post;
use WPGraphQL\Registry\TypeRegistry;
use WPGraphQL\Data\DataSource;

add_action( 'init', __NAMESPACE__ . '\register_content_types' );
/**
 * Registers custom content types.
 */
function register_content_types(): void {
	$content_types = get_registered_content_types();

	if ( ! $content_types ) {
		return;
	}

	foreach ( $content_types as $slug => $args ) {
		$fields = $args['fields'] ?? false;
		unset( $args['fields'] );

		try {
			$args = generate_custom_post_type_args( $args );
		} catch ( InvalidArgumentException $exception ) {
			// Do nothing and let WP use defaults.
		}

		register_post_type( $slug, $args );

		if ( $fields ) {
			register_meta_types( $slug, $fields );
		}
	}
}

/**
 * Registers custom meta with the specific custom post type.
 *
 * @param string $post_type_slug The custom post type slug.
 * @param array  $fields Custom fields to be registered with the custom post type.
 */
function register_meta_types( string $post_type_slug, array $fields ): void {
	register_rest_field(
		$post_type_slug,
		'acm_fields',
		[
			'get_callback' => function( $post, $attr, $request, $object_type ) use ( $fields ) {
				$acm_fields = array();

				foreach ( $fields as $key => $field ) {
					if ( ! $field['show_in_rest'] ) {
						continue;
					}

					$acm_fields[ $field['slug'] ] = handle_content_fields_for_rest_api( $post['id'], $field, $request );
				}

				return $acm_fields;
			},
		]
	);
}

add_action( 'acm_content_connect_init', __NAMESPACE__ . '\\register_relationships' );
/**
 * Registers relationship fields.
 *
 * @param \WPE\AtlasContentModeler\ContentConnect\Registry $registry The relationships registry.
 * @return void
 */
function register_relationships( $registry ) {
	$content_types = get_registered_content_types();

	if ( ! $content_types ) {
		return;
	}

	foreach ( $content_types as $post_type => $args ) {
		if ( ! $args['fields'] ) {
			continue;
		}

		foreach ( $args['fields'] as $field ) {
			if ( $field['type'] === 'relationship' ) {
				$args = [
					'is_bidirectional' => false,
					'from'             => [
						'enable_ui' => true,
						'sortable'  => false,
						'labels'    => [
							'name' => $field['name'],
						],
					],
				];

				try {
					$registry->define_post_to_post( $post_type, $field['reference'], $field['slug'], $args );
				} catch ( \Exception $e ) {
					/**
					 * Either the relationship already exists,
					 * or the referenced post type was deleted.
					 */
				}
			}
		}
	}
}

/**
 * Processes field values for appropriate REST API returns.
 *
 * @param int              $post_id  The post ID of the model post.
 * @param array            $field    The field settings.
 * @param \WP_REST_Request $request  The REST request object.
 *
 * @return array|mixed The Field's value accounting for field type.
 */
function handle_content_fields_for_rest_api( int $post_id, array $field, \WP_REST_Request $request ) {
	$meta_value = get_post_meta( $post_id, $field['slug'], true );

	switch ( $field['type'] ) {
		case 'media':
			$media_item = get_post( $meta_value );

			if ( null === $media_item || 'attachment' !== $media_item->post_type ) {
				return new \stdClass();
			}

			/**
			 * Media data is built to mimic a WordPress attachment's shape and
			 * is therefore based on the WP_REST_Attachments_Controller.
			 *
			 * @see https://developer.wordpress.org/reference/classes/wp_rest_attachments_controller/prepare_item_for_response/
			 */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$caption = apply_filters( 'get_the_excerpt', $media_item->post_excerpt, $media_item );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$caption = apply_filters( 'the_excerpt', $caption );

			$media_data['caption'] = array(
				'raw'      => $media_item->post_excerpt,
				'rendered' => $caption,
			);

			$media_data['alt_text']      = get_post_meta( $media_item->ID, '_wp_attachment_image_alt', true );
			$media_data['media_type']    = wp_attachment_is_image( $media_item->ID ) ? 'image' : 'file';
			$media_data['mime_type']     = $media_item->post_mime_type;
			$media_data['media_details'] = wp_get_attachment_metadata( $media_item->ID );

			// Ensure empty details is an empty object.
			if ( empty( $media_data['media_details'] ) ) {
				$media_data['media_details'] = new \stdClass();
			} elseif ( ! empty( $media_data['media_details']['sizes'] ) ) {
				foreach ( $media_data['media_details']['sizes'] as $size => &$size_data ) {
					if ( isset( $size_data['mime-type'] ) ) {
						$size_data['mime_type'] = $size_data['mime-type'];
						unset( $size_data['mime-type'] );
					}

					// Use the same method image_downsize() does.
					$image_src = wp_get_attachment_image_src( $media_item->ID, $size );
					if ( ! $image_src ) {
						continue;
					}

					$size_data['source_url'] = $image_src[0];
				}

				$full_src = wp_get_attachment_image_src( $media_item->ID, 'full' );

				if ( ! empty( $full_src ) ) {
					$media_data['media_details']['sizes']['full'] = array(
						'file'       => wp_basename( $full_src[0] ),
						'width'      => $full_src[1],
						'height'     => $full_src[2],
						'mime_type'  => $media_item->post_mime_type,
						'source_url' => $full_src[0],
					);
				}
			} else {
				$media_data['media_details']['sizes'] = new \stdClass();
			}

			$media_data['source_url'] = wp_get_attachment_url( $media_item->ID );

			$context            = ! empty( $request['context'] ) ? $request['context'] : 'edit';
			$controller         = new \WP_REST_Attachments_Controller( 'attachment' );
			$attachments_schema = $controller->get_item_schema();

			// Controls output of rendered vs raw captions based on request context.
			$media_data = rest_filter_response_by_context( $media_data, $attachments_schema, $context );

			return $media_data;
		default:
			return $meta_value;
	}
}

/**
 * Generates an array of labels for use when registering custom post types.
 *
 * @see get_post_type_labels()
 *
 * @param array $labels {
 *     Singular and plural labels.
 *     @type string $singular Singular name of post type.
 *     @type string $plural Plural name of post type.
 * }
 *
 * @throws InvalidArgumentException When missing singular or plural arguments.
 * @return array
 */
function generate_custom_post_type_labels( array $labels ): array {
	if ( empty( $labels['singular'] ) || empty( $labels['plural'] ) ) {
		throw new InvalidArgumentException(
			__( 'You must provide both singular and plural labels to generate content type labels.', 'atlas-content-modeler' )
		);
	}

	$singular = $labels['singular'];
	$plural   = $labels['plural'];

	/**
	 * Ignoring these values and using WP defaults:
	 * insert_into_item
	 * add_new
	 * featured_image
	 * set_featured_image
	 * remove_featured_image
	 * use_featured_image
	 * menu_name (same as name)
	 * name_admin_bar (same as singular or name)
	 *
	 * @todo i18n
	 */
	return [
		'name'                     => $plural,
		'singular_name'            => $singular,
		/* translators: %s: singular atlas content modeler field */
		'add_new_item'             => sprintf( __( 'Add new %s', 'atlas-content-modeler' ), $singular ),
		/* translators: %s: singular atlas content modeler field */
		'edit_item'                => sprintf( __( 'Edit %s', 'atlas-content-modeler' ), $singular ),
		/* translators: %s: singular atlas content modeler field */
		'new_item'                 => sprintf( __( 'New %s', 'atlas-content-modeler' ), $singular ),
		/* translators: %s: singular atlas content modeler field */
		'view_item'                => sprintf( __( 'View %s', 'atlas-content-modeler' ), $singular ),
		/* translators: %s: plural atlas content modeler field */
		'view_items'               => sprintf( __( 'View %s', 'atlas-content-modeler' ), $plural ),
		/* translators: %s: plural atlas content modeler field */
		'search_items'             => sprintf( __( 'Search %s', 'atlas-content-modeler' ), $plural ),
		/* translators: %s: plural atlas content modeler field */
		'not_found'                => sprintf( __( 'No %s found', 'atlas-content-modeler' ), $plural ),
		/* translators: %s: plural atlas content modeler field */
		'not_found_in_trash'       => sprintf( __( 'No %s found in trash', 'atlas-content-modeler' ), $plural ),
		/* translators: %s: singular atlas content modeler field */
		'parent_item_colon'        => sprintf( __( 'Parent %s:', 'atlas-content-modeler' ), $singular ),
		/* translators: %s: plural atlas content modeler field */
		'all_items'                => sprintf( __( 'All %s', 'atlas-content-modeler' ), $plural ),
		/* translators: %s: singular atlas content modeler field */
		'archives'                 => sprintf( __( '%s archives', 'atlas-content-modeler' ), $singular ),
		/* translators: %s: singular atlas content modeler field */
		'attributes'               => sprintf( __( '%s Attributes', 'atlas-content-modeler' ), $singular ),
		/* translators: %s: singular atlas content modeler field */
		'uploaded_to_this_item'    => sprintf( __( 'Uploaded to this %s', 'atlas-content-modeler' ), $singular ),
		/* translators: %s: plural atlas content modeler field */
		'filter_items_list'        => sprintf( __( 'Filter %s list', 'atlas-content-modeler' ), $plural ),
		/* translators: %s: plural atlas content modeler field */
		'items_list_navigation'    => sprintf( __( '%s list navigation', 'atlas-content-modeler' ), $plural ),
		/* translators: %s: plural atlas content modeler field */
		'items_list'               => sprintf( __( '%s list', 'atlas-content-modeler' ), $plural ),
		/* translators: %s: singular atlas content modeler field */
		'item_published'           => sprintf( __( '%s published.', 'atlas-content-modeler' ), $singular ),
		/* translators: %s: singular atlas content modeler field */
		'item_published_privately' => sprintf( __( '%s published privately.', 'atlas-content-modeler' ), $singular ),
		/* translators: %s: singular atlas content modeler field */
		'item_reverted_to_draft'   => sprintf( __( '%s reverted to draft.', 'atlas-content-modeler' ), $singular ),
		/* translators: %s: singular atlas content modeler field */
		'item_scheduled'           => sprintf( __( '%s scheduled.', 'atlas-content-modeler' ), $singular ),
		/* translators: %s: singular atlas content modeler field */
		'item_updated'             => sprintf( __( '%s updated.', 'atlas-content-modeler' ), $singular ),
		/* translators: %s: singular atlas content modeler field */
		'parent'                   => sprintf( __( 'Parent %s', 'atlas-content-modeler' ), $singular ),
	];
}

/**
 * Generates an array of arguments for use when registering custom post types.
 *
 * @param array $args Arguments including the singular and plural name of the post type.
 *
 * @throws \InvalidArgumentException When the given arguments are invalid.
 * @return array
 */
function generate_custom_post_type_args( array $args ): array {
	if ( empty( $args['singular'] ) || empty( $args['plural'] ) ) {
		throw new InvalidArgumentException(
			__( 'You must provide both a singular and plural name to register a custom content type.', 'atlas-content-modeler' )
		);
	}

	$singular = $args['singular'];
	$plural   = $args['plural'];

	$labels = generate_custom_post_type_labels(
		[
			'singular' => $singular,
			'plural'   => $plural,
		]
	);

	$return = [
		'name'                  => ucfirst( $plural ),
		'singular_name'         => ucfirst( $singular ),
		'description'           => $args['description'] ?? '',
		'public'                => $args['public'] ?? false,
		'show_ui'               => $args['show_ui'] ?? true,
		'show_in_rest'          => $args['show_in_rest'] ?? true,
		'rest_base'             => $args['rest_base'] ?? sanitize_key( $plural ),
		'capability_type'       => $args['capability_type'] ?? 'post',
		'show_in_menu'          => $args['show_in_menu'] ?? true,
		'supports'              => $args['supports'] ??
								[
									'title',
									'editor',
									'thumbnail',
									'custom-fields',
								],
		'labels'                => $labels,
		'show_in_graphql'       => $args['show_in_graphql'] ?? true,
		'graphql_single_name'   => $args['graphql_single_name'] ?? camelcase( $singular ),
		'graphql_plural_name'   => $args['graphql_plural_name'] ?? camelcase( $plural ),
		'menu_icon'             => ! empty( $args['model_icon'] ) ? $args['model_icon'] : 'dashicons-admin-post',
		'rest_controller_class' => __NAMESPACE__ . '\REST_Posts_Controller',
	];

	if ( ! empty( $args['api_visibility'] ) && 'private' === $args['api_visibility'] ) {
		$return['public'] = false;
	}

	return $return;
}

/**
 * Gets all content types registered with this plugin.
 *
 * @return array
 */
function get_registered_content_types(): array {
	$models = get_option( 'atlas_content_modeler_post_types', array() );

	/**
	 * Maintains backwards compatibility with models that were created
	 * before sanitize_key() was used to format model slugs on creation.
	 *
	 * Existing data will be lazily updated as models are retrieved.
	 *
	 * @todo Consider removing before v1.0.
	 */
	$needs_update   = false;
	$updated_models = [];
	foreach ( $models as $key => $model ) {
		$slug = sanitize_key( $key );

		if ( $key !== $slug ) {
			$needs_update  = true;
			$model['slug'] = $slug;
		}

		$updated_models[ $slug ] = $model;
	}

	if ( $needs_update ) {
		update_registered_content_types( $updated_models );
	}

	return $updated_models;
}

/**
 * Saves the registered content types to the database.
 *
 * This is not a sophisticated function or storage method.
 * It requires you to pass in the full array of content types.
 *
 * @access private This could go away in the future.
 *
 * @param array $args All of the content types and their configuration.
 *
 * @return bool
 */
function update_registered_content_types( array $args ): bool {
	return update_option( 'atlas_content_modeler_post_types', $args );
}

/**
 * Updates an existing content type with the specified arguments.
 *
 * This merges the specified arguments with the existing arguments
 * and updates the content type definition with the merged values.
 *
 * @param string $slug The post type slug.
 * @param array  $args The post type arguments.
 *
 * @return bool
 */
function update_registered_content_type( string $slug, array $args ): bool {
	$types = get_registered_content_types();
	if ( empty( $types[ $slug ] ) ) {
		return false;
	}

	$args = wp_parse_args( $args, $types[ $slug ] );

	/**
	 * If no changes, return true.
	 * Why? update_option returns false and does not update
	 * when the new values match the old values.
	 */
	if ( $types[ $slug ] === $args ) {
		return true;
	}

	$types[ $slug ] = $args;

	return update_registered_content_types( $types );
}

/**
 * Returns post types that have `show_in_graphql` support
 * and were created with this plugin.
 *
 * @return array
 */
function get_graphql_enabled_post_types(): array {
	$gql_post_types = array();
	foreach ( get_registered_content_types() as $slug => $content_type ) {
		if ( ! empty( $content_type['show_in_graphql'] ) ) {
			$gql_post_types[ $slug ] = $content_type;
		}
	}
	return $gql_post_types;
}

add_action( 'graphql_register_types', __NAMESPACE__ . '\register_content_fields_with_graphql' );
/**
 * Registers custom fields with the WPGraphQL API.
 *
 * @param TypeRegistry $type_registry The WPGraphQL Type Registry.
 */
function register_content_fields_with_graphql( TypeRegistry $type_registry ) {
	$models = get_graphql_enabled_post_types();

	foreach ( $models as $model ) {
		if ( empty( $model['fields'] ) ) {
			continue;
		}

		foreach ( $model['fields'] as $field ) {
			if ( empty( $field['show_in_graphql'] ) || empty( $field['slug'] ) ) {
				continue;
			}

			$rich_text = $field['type'] === 'richtext';

			if ( 'relationship' === $field['type'] && isset( $models[ $field['reference'] ] ) ) {
				$reference_model = $models[ $field['reference'] ];
				$from_type       = camelcase( $model['singular'] );
				$to_type         = camelcase( $reference_model['singular'] );
				register_relationship_connection( $from_type, $to_type, $field );
				continue;
			}

			$gql_field_type = map_html_field_type_to_graphql_field_type( $field['type'] );
			if ( empty( $gql_field_type ) ) {
				continue;
			}

			$field['original_type'] = $field['type'];
			$field['type']          = $gql_field_type;

			$field['resolve'] = static function( Post $post, $args, $context, $info ) use ( $field, $rich_text ) {
				if ( 'relationship' !== $field['original_type'] ) {
					$value = get_post_meta( $post->databaseId, $field['slug'], true );

					/**
					 * If WPGraphQL expects a float and something else is returned instead
					 * it causes a runaway PHP process and it eventually dies due to
					 * to timeout issues. Casting to a float is a temporary fix until
					 * we get a proper fix upstream or build something more robust here.
					 *
					 * @todo
					 */
					if ( $field['type'] === 'Float' ) {
						return (float) $value;
					}

					if ( $field['type'] === 'MediaItem' ) {
						return DataSource::resolve_post_object( (int) $value, $context );
					}

					// fixes caption shortcode for graphql output.
					if ( $rich_text ) {
						return do_shortcode( $value );
					}

					return $value;
				}
			};

			// @todo
			// WPGraphQL will use 'name' if present. Our 'name' is display friendly. WPGraphQL needs slug friendly.
			unset( $field['name'] );

			register_graphql_field(
				camelcase( $model['singular'] ),
				camelcase( $field['slug'] ),
				$field
			);
		}
	}
}

add_filter( 'graphql_data_is_private', __NAMESPACE__ . '\graphql_data_is_private', 10, 6 );
/**
 * Determines whether or not data should be considered private in WPGraphQL.
 *
 * Accessing private data requires authentication and proper authorization.
 *
 * @param boolean     $is_private Whether or not the model is private.
 * @param string      $model_name Name of the model.
 * @param \WP_Post    $post The post object.
 * @param string|null $visibility The visibility that has currently been set for the post at this point.
 * @param int|null    $owner The post author's user ID.
 * @param \WP_User    $current_user The current user.
 * @return bool
 */
function graphql_data_is_private( bool $is_private, string $model_name, $post, $visibility, $owner, \WP_User $current_user ): bool {
	if ( ! is_object( $post ) ) {
		return $is_private;
	}

	if ( 'WP_Post' !== get_class( $post ) ) {
		return $is_private;
	}

	$models = get_registered_content_types();
	if ( array_key_exists( $post->post_type, $models ) && isset( $models[ $post->post_type ]['api_visibility'] ) && 'private' === $models[ $post->post_type ]['api_visibility'] ) {
		$post_type  = get_post_type_object( $post->post_type );
		$is_private = ! user_can( $current_user, $post_type->cap->read_post, $post->ID );
	}

	return $is_private;
}

/**
 * Registers the relationship field as a GraphQL connection.
 *
 * @param string $from_type The post_type of the parent.
 * @param string $to_type The post_type of the connection's destination.
 * @param array  $field The field data.
 */
function register_relationship_connection( string $from_type, string $to_type, array $field ) {
	$connection_type_name = get_connection_name( $from_type, $to_type, $field['slug'] );

	register_graphql_connection(
		array(
			'fromType'           => $from_type,
			'toType'             => $to_type,
			'fromFieldName'      => $field['slug'],
			'oneToOne'           => ( $field['cardinality'] === 'one-to-one' ),
			'resolve'            => static function ( Post $post, $args, $context, $info ) use ( $field ) {
				$registry = \WPE\AtlasContentModeler\ContentConnect\Plugin::instance()->get_registry();

				$relationship = $registry->get_post_to_post_relationship(
					$post->post_type,
					$field['reference'],
					$field['slug']
				);

				$relationship_ids = $relationship->get_related_object_ids( $post->ID );

				if ( empty( $relationship_ids ) ) {
					return array();
				}

				$resolver = new PostObjectConnectionResolver(
					$post,
					$args,
					$context,
					$info
				);

				$resolver->set_query_arg(
					'post__in',
					$relationship_ids
				);

				if ( $field['cardinality'] === 'one-to-one' ) {
					return $resolver->one_to_one()->get_connection();
				}

				return $resolver->get_connection();
			},
			'connectionTypeName' => $connection_type_name,
		)
	);

	return $connection_type_name;
}

/**
 * Creates a unique name for the connection
 *
 * @param string $from_type The post type the connection is from.
 * @param string $to_type The post type the connection is to.
 * @param string $from_field_name  Acts as an alternative 'toType' if connection type already defined using $to_type.
 *
 * @return string
 */
function get_connection_name( string $from_type, string $to_type, string $from_field_name ): string {

	// Create connection name using $from_type + To + $to_type + Connection.
	$connection_name = ucfirst( $from_type ) . 'To' . ucfirst( $to_type ) . 'Connection';

	$type_registry = \WPGraphQL::get_type_registry();

	// If connection type already exists with that connection name. Set connection name using
	// $from_field_name + To + $to_type + Connection.
	if ( $type_registry->has_type( $connection_name ) ) {
		$connection_name = ucfirst( $from_type ) . 'To' . ucfirst( $from_field_name ) . 'Connection';
	}

	return $connection_name;
}


/**
 * Maps an HTML field type to a WPGraphQL field type.
 *
 * @param string $field_type The HTML field type.
 *
 * @access private
 *
 * @return string|null
 */
function map_html_field_type_to_graphql_field_type( string $field_type ): ?string {
	if ( empty( $field_type ) ) {
		return null;
	}

	switch ( $field_type ) {
		case 'text':
		case 'textarea':
		case 'string':
		case 'date':
		case 'richtext':
			return 'String';
		case 'number':
			return 'Float';
		case 'boolean':
			return 'Boolean';
		case 'media':
			return 'MediaItem';
		default:
			return null;
	}
}

add_filter( 'is_protected_meta', __NAMESPACE__ . '\is_protected_meta', 10, 3 );
/**
 * Designates fields from this plugin as protected to prevent them
 * from showing in the Custom Fields metabox on other post types.
 *
 * @param bool   $protected Whether the key is considered protected.
 * @param string $meta_key  Metadata key.
 * @param string $meta_type Type of object metadata is for. Accepts 'post', 'comment', 'term', 'user',
 *                          or any other object type with an associated meta table.
 */
function is_protected_meta( bool $protected, string $meta_key, string $meta_type ): bool {
	// Return early if already protected.
	if ( true === $protected ) {
		return $protected;
	}

	if ( 'post' !== $meta_type ) {
		return $protected;
	}

	$fields = wp_list_pluck( get_registered_content_types(), 'fields' );
	$fields = array_merge( ...array_values( $fields ) );
	$slugs  = wp_list_pluck( $fields, 'slug' );

	return in_array( $meta_key, $slugs, true );
}

/**
 * Converts string to camelCase. Added to ensure that fields are compliant with the GraphQL spec.
 *
 * @param string $str The string to be converted to camelCase.
 * @param array  $preserved_chars The characters to preserve.
 *
 * @credit http://www.mendoweb.be/blog/php-convert-string-to-camelcase-string/
 *
 * @return string camelCase'd string
 */
function camelcase( string $str, array $preserved_chars = array() ): string {
	/* Convert non-alpha and non-numeric characters to spaces. */
	$str = preg_replace( '/[^a-z0-9' . implode( '', $preserved_chars ) . ']+/i', ' ', $str );
	$str = trim( $str );

	/* Uppercase the first character of each word. */
	$str = ucwords( $str );
	$str = str_replace( ' ', '', $str );

	return lcfirst( $str );
}
