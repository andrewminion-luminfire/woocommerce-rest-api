<?php
/**
 * REST API Taxes controller
 *
 * Handles requests to the /taxes endpoint.
 *
 * @package Automattic/WooCommerce/RestApi
 */

namespace Automattic\WooCommerce\RestApi\Controllers\Version4;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\RestApi\Controllers\Version4\Utilities\Pagination;

/**
 * REST API Taxes controller class.
 */
class Taxes extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'taxes';

	/**
	 * Permission to check.
	 *
	 * @var string
	 */
	protected $resource_type = 'settings';

	/**
	 * Register the routes for taxes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			),
			true
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the resource.', 'woocommerce-rest-api' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'force' => array(
							'default'     => false,
							'type'        => 'boolean',
							'description' => __( 'Required to be true, as resource does not support trashing.', 'woocommerce-rest-api' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			),
			true
		);

		$this->register_batch_route();
	}

	/**
	 * Get all taxes and allow filtering by tax code.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_items( $request ) {
		global $wpdb;

		$prepared_args           = array();
		$prepared_args['order']  = $request['order'];
		$prepared_args['number'] = $request['per_page'];
		if ( ! empty( $request['offset'] ) ) {
			$prepared_args['offset'] = $request['offset'];
		} else {
			$prepared_args['offset'] = ( $request['page'] - 1 ) * $prepared_args['number'];
		}
		$orderby_possibles        = array(
			'id'    => 'tax_rate_id',
			'order' => 'tax_rate_order',
		);
		$prepared_args['orderby'] = $orderby_possibles[ $request['orderby'] ];
		$prepared_args['class']   = $request['class'];
		$prepared_args['code']    = $request['code'];
		$prepared_args['include'] = $request['include'];

		/**
		 * Filter arguments, before passing to $wpdb->get_results(), when querying taxes via the REST API.
		 *
		 * @param array           $prepared_args Array of arguments for $wpdb->get_results().
		 * @param \WP_REST_Request $request       The current request.
		 */
		$prepared_args = apply_filters( 'woocommerce_rest_tax_query', $prepared_args, $request );

		$query = "
			SELECT *
			FROM {$wpdb->prefix}woocommerce_tax_rates
			WHERE 1 = 1
		";

		// Filter by tax class.
		if ( ! empty( $prepared_args['class'] ) ) {
			$class  = 'standard' !== $prepared_args['class'] ? sanitize_title( $prepared_args['class'] ) : '';
			$query .= " AND tax_rate_class = '$class'";
		}

		// Filter by tax code.
		$tax_code_search = $prepared_args['code'];
		if ( $tax_code_search ) {
			$tax_code_search = $wpdb->esc_like( $tax_code_search );
			$tax_code_search = ' \'%' . $tax_code_search . '%\'';
			$query          .= ' AND CONCAT_WS( "-", NULLIF(tax_rate_country, ""), NULLIF(tax_rate_state, ""), NULLIF(tax_rate_name, ""), NULLIF(tax_rate_priority, "") ) LIKE ' . $tax_code_search;
		}

		// Filter by included tax rate IDs.
		$included_taxes = $prepared_args['include'];
		if ( ! empty( $included_taxes ) ) {
			$included_taxes = implode( ',', $prepared_args['include'] );
			$query         .= " AND tax_rate_id IN ({$included_taxes})";
		}

		// Order tax rates.
		$order_by = sprintf( ' ORDER BY %s', sanitize_key( $prepared_args['orderby'] ) );

		// Pagination.
		$pagination = sprintf( ' LIMIT %d, %d', $prepared_args['offset'], $prepared_args['number'] );

		// Query taxes.
		$results = $wpdb->get_results( $query . $order_by . $pagination ); // @codingStandardsIgnoreLine.

		$taxes = array();
		foreach ( $results as $tax ) {
			$data    = $this->prepare_item_for_response( $tax, $request );
			$taxes[] = $this->prepare_response_for_collection( $data );
		}

		// Store pagination values for headers then unset for count query.
		$per_page = (int) $prepared_args['number'];
		$page     = ceil( ( ( (int) $prepared_args['offset'] ) / $per_page ) + 1 );

		// Query only for ids.
		$wpdb->get_results( str_replace( 'SELECT *', 'SELECT tax_rate_id', $query ) ); // @codingStandardsIgnoreLine.

		// Calculate totals.
		$total_taxes = (int) $wpdb->num_rows;
		$max_pages   = ceil( $total_taxes / $per_page );

		$response = rest_ensure_response( $taxes );
		$response = Pagination::add_pagination_headers( $response, $request, $total_taxes, $max_pages );

		return $response;
	}

	/**
	 * Take tax data from the request and return the updated or newly created rate.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @param \stdClass|null   $current Existing tax object.
	 * @return object
	 */
	protected function create_or_update_tax( $request, $current = null ) {
		$id          = absint( isset( $request['id'] ) ? $request['id'] : 0 );
		$data        = array();
		$fields      = array(
			'tax_rate_country',
			'tax_rate_state',
			'tax_rate',
			'tax_rate_name',
			'tax_rate_priority',
			'tax_rate_compound',
			'tax_rate_shipping',
			'tax_rate_order',
			'tax_rate_class',
		);

		foreach ( $fields as $field ) {
			// Keys via API differ from the stored names returned by _get_tax_rate.
			$key = 'tax_rate' === $field ? 'rate' : str_replace( 'tax_rate_', '', $field );

			// Remove data that was not posted.
			if ( ! isset( $request[ $key ] ) ) {
				continue;
			}

			// Test new data against current data.
			if ( $current && $current->$field === $request[ $key ] ) {
				continue;
			}

			// Add to data array.
			switch ( $key ) {
				case 'tax_rate_priority':
				case 'tax_rate_compound':
				case 'tax_rate_shipping':
				case 'tax_rate_order':
					$data[ $field ] = absint( $request[ $key ] );
					break;
				case 'tax_rate_class':
					$data[ $field ] = 'standard' !== $request['tax_rate_class'] ? $request['tax_rate_class'] : '';
					break;
				default:
					$data[ $field ] = wc_clean( $request[ $key ] );
					break;
			}
		}

		if ( $id ) {
			\WC_Tax::_update_tax_rate( $id, $data );
		} else {
			$id = \WC_Tax::_insert_tax_rate( $data );
		}

		// Add locales.
		if ( ! empty( $request['postcode'] ) ) {
			\WC_Tax::_update_tax_rate_postcodes( $id, wc_clean( $request['postcode'] ) );
		}
		if ( ! empty( $request['city'] ) ) {
			\WC_Tax::_update_tax_rate_cities( $id, wc_clean( $request['city'] ) );
		}

		return \WC_Tax::_get_tax_rate( $id, OBJECT );
	}

	/**
	 * Create a single tax.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function create_item( $request ) {
		if ( ! empty( $request['id'] ) ) {
			return new \WP_Error( 'woocommerce_rest_tax_exists', __( 'Cannot create existing resource.', 'woocommerce-rest-api' ), array( 'status' => 400 ) );
		}

		$tax = $this->create_or_update_tax( $request );

		$this->update_additional_fields_for_object( $tax, $request );

		/**
		 * Fires after a tax is created or updated via the REST API.
		 *
		 * @param \stdClass        $tax       Data used to create the tax.
		 * @param \WP_REST_Request $request   Request object.
		 * @param boolean         $creating  True when creating tax, false when updating tax.
		 */
		do_action( 'woocommerce_rest_insert_tax', $tax, $request, true );

		$request->set_param( 'context', 'edit' );
		$response = $this->prepare_item_for_response( $tax, $request );
		$response = rest_ensure_response( $response );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $tax->tax_rate_id ) ) );

		return $response;
	}

	/**
	 * Get a single tax.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_item( $request ) {
		$id      = (int) $request['id'];
		$tax_obj = \WC_Tax::_get_tax_rate( $id, OBJECT );

		if ( empty( $id ) || empty( $tax_obj ) ) {
			return new \WP_Error( 'woocommerce_rest_invalid_id', __( 'Invalid resource ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		$tax      = $this->prepare_item_for_response( $tax_obj, $request );
		$response = rest_ensure_response( $tax );

		return $response;
	}

	/**
	 * Update a single tax.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_item( $request ) {
		$id      = (int) $request['id'];
		$tax_obj = \WC_Tax::_get_tax_rate( $id, OBJECT );

		if ( empty( $id ) || empty( $tax_obj ) ) {
			return new \WP_Error( 'woocommerce_rest_invalid_id', __( 'Invalid resource ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		$tax = $this->create_or_update_tax( $request, $tax_obj );

		$this->update_additional_fields_for_object( $tax, $request );

		/**
		 * Fires after a tax is created or updated via the REST API.
		 *
		 * @param \stdClass        $tax       Data used to create the tax.
		 * @param \WP_REST_Request $request   Request object.
		 * @param boolean         $creating  True when creating tax, false when updating tax.
		 */
		do_action( 'woocommerce_rest_insert_tax', $tax, $request, false );

		$request->set_param( 'context', 'edit' );
		$response = $this->prepare_item_for_response( $tax, $request );
		$response = rest_ensure_response( $response );

		return $response;
	}

	/**
	 * Delete a single tax.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function delete_item( $request ) {
		global $wpdb;

		$id    = (int) $request['id'];
		$force = isset( $request['force'] ) ? (bool) $request['force'] : false;

		// We don't support trashing for this type, error out.
		if ( ! $force ) {
			return new \WP_Error( 'woocommerce_rest_trash_not_supported', __( 'Taxes do not support trashing.', 'woocommerce-rest-api' ), array( 'status' => 501 ) );
		}

		$tax = \WC_Tax::_get_tax_rate( $id, OBJECT );

		if ( empty( $id ) || empty( $tax ) ) {
			return new \WP_Error( 'woocommerce_rest_invalid_id', __( 'Invalid resource ID.', 'woocommerce-rest-api' ), array( 'status' => 400 ) );
		}

		$request->set_param( 'context', 'edit' );
		$response = $this->prepare_item_for_response( $tax, $request );

		\WC_Tax::_delete_tax_rate( $id );

		if ( 0 === $wpdb->rows_affected ) {
			return new \WP_Error( 'woocommerce_rest_cannot_delete', __( 'The resource cannot be deleted.', 'woocommerce-rest-api' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after a tax is deleted via the REST API.
		 *
		 * @param \stdClass         $tax      The tax data.
		 * @param \WP_REST_Response $response The response returned from the API.
		 * @param \WP_REST_Request  $request  The request sent to the API.
		 */
		do_action( 'woocommerce_rest_delete_tax', $tax, $response, $request );

		return $response;
	}

	/**
	 * Get data for this object in the format of this endpoint's schema.
	 *
	 * @param \stdClass        $object Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return array Array of data in the correct format.
	 */
	protected function get_data_for_response( $object, $request ) {
		global $wpdb;

		$id   = (int) $object->tax_rate_id;
		$data = array(
			'id'       => $id,
			'country'  => $object->tax_rate_country,
			'state'    => $object->tax_rate_state,
			'postcode' => '',
			'city'     => '',
			'rate'     => $object->tax_rate,
			'name'     => $object->tax_rate_name,
			'priority' => (int) $object->tax_rate_priority,
			'compound' => (bool) $object->tax_rate_compound,
			'shipping' => (bool) $object->tax_rate_shipping,
			'order'    => (int) $object->tax_rate_order,
			'class'    => $object->tax_rate_class ? $object->tax_rate_class : 'standard',
		);

		// Get locales from a tax rate.
		$locales = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT location_code, location_type
				FROM {$wpdb->prefix}woocommerce_tax_rate_locations
				WHERE tax_rate_id = %d
				",
				$id
			)
		);

		if ( ! is_wp_error( $locales ) && ! is_null( $locales ) ) {
			foreach ( $locales as $locale ) {
				$data[ $locale->location_type ] = $locale->location_code;
			}
		}
		return $data;
	}

	/**
	 * Prepare links for the request.
	 *
	 * @param mixed            $item Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	protected function prepare_links( $item, $request ) {
		$links = array(
			'self' => array(
				'href' => rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $item->tax_rate_id ) ),
			),
			'collection' => array(
				'href' => rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ),
			),
		);

		return $links;
	}

	/**
	 * Get the Taxes schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'tax',
			'type'       => 'object',
			'properties' => array(
				'id' => array(
					'description' => __( 'Unique identifier for the resource.', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'country' => array(
					'description' => __( 'Country ISO 3166 code.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'state' => array(
					'description' => __( 'State code.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'postcode' => array(
					'description' => __( 'Postcode / ZIP.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'city' => array(
					'description' => __( 'City name.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'rate' => array(
					'description' => __( 'Tax rate.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'name' => array(
					'description' => __( 'Tax rate name.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'priority' => array(
					'description' => __( 'Tax priority.', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'default'     => 1,
					'context'     => array( 'view', 'edit' ),
				),
				'compound' => array(
					'description' => __( 'Whether or not this is a compound rate.', 'woocommerce-rest-api' ),
					'type'        => 'boolean',
					'default'     => false,
					'context'     => array( 'view', 'edit' ),
				),
				'shipping' => array(
					'description' => __( 'Whether or not this tax rate also gets applied to shipping.', 'woocommerce-rest-api' ),
					'type'        => 'boolean',
					'default'     => true,
					'context'     => array( 'view', 'edit' ),
				),
				'order' => array(
					'description' => __( 'Indicates the order that will appear in queries.', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'class' => array(
					'description' => __( 'Tax class.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'default'     => 'standard',
					'enum'        => array_merge( array( 'standard' ), \WC_Tax::get_tax_class_slugs() ),
					'context'     => array( 'view', 'edit' ),
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Get the query params for collections.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params                       = array();
		$params['context']            = $this->get_context_param();
		$params['context']['default'] = 'view';

		$params['page'] = array(
			'description'        => __( 'Current page of the collection.', 'woocommerce-rest-api' ),
			'type'               => 'integer',
			'default'            => 1,
			'sanitize_callback'  => 'absint',
			'validate_callback'  => 'rest_validate_request_arg',
			'minimum'            => 1,
		);
		$params['per_page'] = array(
			'description'        => __( 'Maximum number of items to be returned in result set.', 'woocommerce-rest-api' ),
			'type'               => 'integer',
			'default'            => 10,
			'minimum'            => 1,
			'maximum'            => 100,
			'sanitize_callback'  => 'absint',
			'validate_callback'  => 'rest_validate_request_arg',
		);
		$params['offset'] = array(
			'description'        => __( 'Offset the result set by a specific number of items.', 'woocommerce-rest-api' ),
			'type'               => 'integer',
			'sanitize_callback'  => 'absint',
			'validate_callback'  => 'rest_validate_request_arg',
		);
		$params['order'] = array(
			'default'            => 'asc',
			'description'        => __( 'Order sort attribute ascending or descending.', 'woocommerce-rest-api' ),
			'enum'               => array( 'asc', 'desc' ),
			'sanitize_callback'  => 'sanitize_key',
			'type'               => 'string',
			'validate_callback'  => 'rest_validate_request_arg',
		);
		$params['orderby'] = array(
			'default'            => 'order',
			'description'        => __( 'Sort collection by object attribute.', 'woocommerce-rest-api' ),
			'enum'               => array(
				'id',
				'order',
			),
			'sanitize_callback'  => 'sanitize_key',
			'type'               => 'string',
			'validate_callback'  => 'rest_validate_request_arg',
		);
		$params['class'] = array(
			'description'        => __( 'Sort by tax class.', 'woocommerce-rest-api' ),
			'enum'               => array_merge( array( 'standard' ), \WC_Tax::get_tax_class_slugs() ),
			'sanitize_callback'  => 'sanitize_title',
			'type'               => 'string',
			'validate_callback'  => 'rest_validate_request_arg',
		);
		$params['code']    = array(
			'description'       => __( 'Search by similar tax code.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['include'] = array(
			'description'       => __( 'Limit result set to items that have the specified rate ID(s) assigned.', 'woocommerce-rest-api' ),
			'type'              => 'array',
			'items'             => array(
				'type' => 'integer',
			),
			'default'           => array(),
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}
}
