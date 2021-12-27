<?php
/**
 * Plugin Name: Torob for Woocommerce
 * Description: Product Extractor for Woocommerce
 * Version: 1.2.2
 * Author: Reza Esmaeili
 * Author URI: https://rezaesmaeili.ir/
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: torob
 */

if(!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// Setting a custom timeout value for cURL. Using a high value for priority to ensure the function runs after any other added to the same action hook.
add_action('http_api_curl', 'wcpe_curl_timeout', 9999, 1);
function wcpe_curl_timeout($handle)
{
	curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 12);
	curl_setopt($handle, CURLOPT_TIMEOUT, 12);
}

// Setting custom timeout for the HTTP request
add_filter('http_request_timeout', 'wcpe_http_request_timeout', 9999);
function wcpe_http_request_timeout($timeout_value)
{
	return 12;
}

// Setting custom timeout in HTTP request args
add_filter('http_request_args', 'wcpe_http_request_args', 9999, 1);
function wcpe_http_request_args($r)
{
	$r['timeout'] = 12;
	return $r;
}

// Check if WooCommerce is active
if(in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
	class WC_Products_Extractor extends WP_REST_Controller
	{
		private $wcpe_version = "1.2.2";
		private $plugin_slug = "torob/wcpe.php";
		private $text_domain_slug = "torob";

		public function __construct()
		{
			add_action('rest_api_init', array($this, 'register_routes'));
		}

		/**
		 * Check for new updates
		 */
		private function auto_update()
		{
			$result = FALSE;
			try {
				ob_start(function() {
					return '';
				});
				include_once ABSPATH . '/wp-admin/includes/file.php';
				include_once ABSPATH . '/wp-admin/includes/misc.php';
				include_once ABSPATH . '/wp-includes/pluggable.php';
				include_once ABSPATH . '/wp-admin/includes/plugin.php';
				include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';
				if(is_plugin_active($this->plugin_slug)) {
					$upgrader = new Plugin_Upgrader();
					$result = $upgrader->upgrade($this->plugin_slug);
					activate_plugin($this->plugin_slug);
				}
				@ob_end_clean();
			} catch(Exception $e) {
				activate_plugin($this->plugin_slug);
			}
			return $result;
		}

		/**
		 * find mathing product and variation
		 */
		private function find_matching_variation($product, $attributes)
		{
			foreach($attributes as $key => $value) {
				if(strpos($key, 'attribute_') === 0) {
					continue;
				}
				unset($attributes[$key]);
				$attributes[sprintf('attribute_%s', $key)] = $value;
			}
			if(class_exists('WC_Data_Store')) {
				$data_store = WC_Data_Store::load('product');
				return $data_store->find_matching_product_variation($product, $attributes);
			} else {
				return $product->get_matching_variation($attributes);
			}
		}

		/**
		 * Register rout: https://domain.com/wcpe/v1/products
		 */
		public function register_routes()
		{
			$version = '1';
			$namespace = 'wcpe/v' . $version;
			$base = 'products';
			register_rest_route($namespace, '/' . $base, array(
				array(
					'methods' => 'POST',
					'callback' => array(
						$this,
						'get_products'
					),
					'permission_callback' => '__return_true',
					'args' => array()
				)
			));
		}

		/**
		 * Check update and validate the request
		 * @param request
		 * @return wp_safe_remote_post
		 */
		public function check_request($request)
		{
			// Check and update plugin for first request
			if(!empty($request->get_param('auto_update'))) {
				$update_switch = rest_sanitize_boolean($request->get_param('auto_update'));
			} else {
				$update_switch = TRUE;
			}
			if($update_switch) {
				if($this->auto_update()) {
					exit();
				}
			}

			// Get shop domain
			$site_url = wp_parse_url(get_site_url());
			$shop_domain = str_replace('www.', '', $site_url['host']);

			// torob verify token url
			$endpoint_url = 'https://extractor.torob.com/validate_token/';

			// Get Parameters
			$token = sanitize_text_field($request->get_param('token'));

			// Get Headers
			$header = $request->get_header('X-Authorization');
			if(empty($header)) {
				$header = $request->get_header('Authorization');
			}

			// Verify token
			$response = wp_safe_remote_post($endpoint_url, array(
					'method' => 'POST',
					'timeout' => 5,
					'redirection' => 0,
					'httpversion' => '1.0',
					'blocking' => true,
					'headers' => array(
						'AUTHORIZATION' => $header,
					),
					'body' => array(
						'token' => $token,
						'shop_domain' => $shop_domain,
						'version' => $this->wcpe_version
					),
					'cookies' => array()
				)
			);

			return $response;
		}


		/**
		 * Get single product values
		 */
		public function get_product_values($product, $is_child = FALSE)
		{
			$temp_product = new \stdClass();
			$parent = NULL;
			if($is_child) {
				$parent = wc_get_product($product->get_parent_id());
				$temp_product->title = $parent->get_name();
				$temp_product->subtitle = get_post_meta($product->get_parent_id(), 'product_english_name', true);
				$cat_ids = $parent->get_category_ids();
				$temp_product->parent_id = $parent->get_id();
			} else {
				$temp_product->title = $product->get_name();
				$temp_product->subtitle = get_post_meta($product->get_id(), 'product_english_name', true);
				$cat_ids = $product->get_category_ids();
				$temp_product->parent_id = 0;
			}
			$temp_product->page_unique = $product->get_id();
			$temp_product->current_price = $product->get_price();
			$temp_product->old_price = $product->get_regular_price();
			$temp_product->availability = $product->get_stock_status();
			$temp_product->category_name = get_term_by('id', end($cat_ids), 'product_cat', 'ARRAY_A')['name'];
			$t_image = wp_get_attachment_image_src($product->get_image_id(), 'full');
			if($t_image) {
				$temp_product->image_link = $t_image[0];
			} else {
				$temp_product->image_link = null;
			}
			$temp_product->page_url = get_permalink($product->get_id());
			$temp_product->short_desc = $product->get_short_description();
			$temp_product->spec = array();
			$temp_product->date = $product->get_date_created();
			$temp_product->registry = '';
			$temp_product->guarantee = '';

			if(!$is_child) {
				if($product->is_type('variable')) {
					// Set prices to 0 then calcualte them
					$temp_product->current_price = 0;
					$temp_product->old_price = 0;

					// Find price for default attributes. If can't find return max price of variations
					$variation_id = $this->find_matching_variation($product, $product->get_default_attributes());
					if($variation_id != 0) {
						$variation = wc_get_product($variation_id);
						$temp_product->current_price = $variation->get_price();
						$temp_product->old_price = $variation->get_regular_price();
						$temp_product->availability = $variation->get_stock_status();
					} else {
						$price_var = array();
						$variation_ids = $product->get_children();
						foreach($variation_ids as $variation_id) {
							$variation = wc_get_product($variation_id);
							if($variation->is_in_stock()) {
								$price = $variation->get_price();
								array_push($price_var, $price);
							}
						}
						$price_var = min($price_var);

						if(!empty($price_var)) {
							$temp_product->current_price = $price_var;
							$temp_product->old_price = $price_var;
						} else {
							$temp_product->current_price = $product->get_variation_price();
							$temp_product->old_price = $product->get_variation_regular_price();
						}
					}

					// Extract default attributes
					foreach($product->get_default_attributes() as $key => $value) {
						if(!empty($value)) {
							if(substr($key, 0, 3) === 'pa_') {
								$value = get_term_by('slug', $value, $key);
								if($value) {
									$value = $value->name;
								} else {
									$value = '';
								}
								$key = wc_attribute_label($key);
								$temp_product->spec[urldecode($key)] = rawurldecode($value);
							} else {
								$temp_product->spec[urldecode($key)] = rawurldecode($value);
							}
						}
					}
				}
				// add remain attributes
				foreach($product->get_attributes() as $attribute) {
					if($attribute['visible'] == 1) {
						$name = wc_attribute_label($attribute['name']);
						if(substr($attribute['name'], 0, 3) === 'pa_') {
							$values = wc_get_product_terms($product->get_id(), $attribute['name'], array('fields' => 'names'));
						} else {
							$values = $attribute['options'];
						}
						if(!array_key_exists($name, $temp_product->spec)) {
							$temp_product->spec[$name] = implode(', ', $values);
						}
					}
				}
			} else {
				foreach($product->get_attributes() as $key => $value) {
					if(!empty($value)) {
						if(substr($key, 0, 3) === 'pa_') {
							$value = get_term_by('slug', $value, $key);
							if($value) {
								$value = $value->name;
							} else {
								$value = '';
							}
							$key = wc_attribute_label($key);
							$temp_product->spec[urldecode($key)] = rawurldecode($value);
						} else {
							$temp_product->spec[urldecode($key)] = rawurldecode($value);
						}
					}
				}
			}

			// Set registry and guarantee
			if(!empty($temp_product->spec['Ø±Ø¬ÛŒØ³ØªØ±ÛŒ'])) {
				$temp_product->registry = $temp_product->spec['Ø±Ø¬ÛŒØ³ØªØ±ÛŒ'];
			} elseif(!empty($temp_product->spec['registry'])) {
				$temp_product->registry = $temp_product->spec['registry'];
			} elseif(!empty($temp_product->spec['Ø±ÛŒØ¬ÛŒØ³ØªØ±ÛŒ'])) {
				$temp_product->registry = $temp_product->spec['Ø±ÛŒØ¬ÛŒØ³ØªØ±ÛŒ'];
			} elseif(!empty($temp_product->spec['Ø±ÛŒØ¬Ø³ØªØ±ÛŒ'])) {
				$temp_product->registry = $temp_product->spec['Ø±ÛŒØ¬Ø³ØªØ±ÛŒ'];
			}

			$guarantee_keys = [
				"Ú¯Ø§Ø±Ø§Ù†ØªÛŒ",
				"guarantee",
				"warranty",
				"garanty",
				"Ú¯Ø§Ø±Ø§Ù†ØªÛŒ:",
				"Ú¯Ø§Ø±Ø§Ù†ØªÛŒ Ù…Ø­ØµÙˆÙ„",
				"Ú¯Ø§Ø±Ø§Ù†ØªÛŒ Ù…Ø­ØµÙˆÙ„:",
				"Ø¶Ù…Ø§Ù†Øª",
				"Ø¶Ù…Ø§Ù†Øª:"
			];

			foreach($guarantee_keys as $guarantee) {
				if(!empty($temp_product->spec[$guarantee])) {
					$temp_product->guarantee = $temp_product->spec[$guarantee];
				}
			}

			if(!array_key_exists('Ø´Ù†Ø§Ø³Ù‡ Ú©Ø§Ù„Ø§', $temp_product->spec)) {
				$sku = $product->get_sku();
				if($sku != "") {
					$temp_product->spec['Ø´Ù†Ø§Ø³Ù‡ Ú©Ø§Ù„Ø§'] = $sku;
				}
			}

			if(count($temp_product->spec) > 0) {
				$temp_product->spec = [$temp_product->spec];
			}

			return $temp_product;
		}

		/**
		 * Get all products
		 *
		 * @param WP_REST_Request $request Full data about the request.
		 * @return WP_Error|WP_REST_Response
		 */
		private function get_all_products($show_variations, $limit, $page)
		{
			$parent_ids = array();
			if($show_variations) {
				// Get all posts have children
				$query = new WP_Query(array(
					'post_type' => array('product_variation'),
					'post_status' => 'publish'
				));
				$products = $query->get_posts();
				$parent_ids = array_column($products, 'post_parent');

				// Make query
				$query = new WP_Query(array(
					'posts_per_page' => $limit,
					'paged' => $page,
					'post_status' => 'publish',
					'orderby' => 'ID',
					'order' => 'DESC',
					'post_type' => array('product', 'product_variation'),
					'post__not_in' => $parent_ids
				));
				$products = $query->get_posts();
			} else {
				// Make query
				$query = new WP_Query(array(
					'posts_per_page' => $limit,
					'paged' => $page,
					'post_status' => 'publish',
					'orderby' => 'ID',
					'order' => 'DESC',
					'post_type' => array('product')
				));
				$products = $query->get_posts();
			}

			// Count products
			$data['count'] = $query->found_posts;

			// Total pages
			$data['max_pages'] = $query->max_num_pages;

			$data['products'] = array();

			// Retrive and send data in json
			foreach($products as $product) {
				$product = wc_get_product($product->ID);
				$parent_id = $product->get_parent_id();
				// Process for parent product
				if($parent_id == 0) {
					// Exclude the variable product. (variations of it will be inserted.)
					if($show_variations) {
						if(!$product->is_type('variable')) {
							$temp_product = $this->get_product_values($product);
							$data['products'][] = $this->prepare_response_for_collection($temp_product);
						}
					} else {
						$temp_product = $this->get_product_values($product);
						$data['products'][] = $this->prepare_response_for_collection($temp_product);
					}
				} else {
					// Process for visible child
					if($product->get_price()) {
						$temp_product = $this->get_product_values($product, TRUE);
						$data['products'][] = $this->prepare_response_for_collection($temp_product);
					}
				}
			}
			return $data;
		}

		/**
		 * Get a product or list of products
		 *
		 * @param WP_REST_Request $request Full data about the request.
		 * @return WP_Error|WP_REST_Response
		 */
		private function get_list_products($product_list)
		{
			$data['products'] = array();

			// Retrive and send data in json
			foreach($product_list as $pid) {
				$product = wc_get_product($pid);
				if($product && $product->get_status() === "publish") {
					$parent_id = $product->get_parent_id();
					// Process for parent product
					if($parent_id == 0) {
						$temp_product = $this->get_product_values($product);
						$data['products'][] = $this->prepare_response_for_collection($temp_product);
					} else {
						// Process for visible child
						if($product->get_price()) {
							$temp_product = $this->get_product_values($product, TRUE);
							$data['products'][] = $this->prepare_response_for_collection($temp_product);
						}
					}
				}
			}
			return $data;
		}

		/**
		 * Get a slugs or list of slugs. For getting product's data by its link
		 *
		 * @param WP_REST_Request $request Full data about the request.
		 * @return WP_Error|WP_REST_Response
		 */
		private function get_list_slugs($slug_list)
		{
			$data['products'] = array();

			// Retrive and send data in json
			foreach($slug_list as $sid) {
				$product = get_page_by_path($sid, OBJECT, 'product');
				if($product && $product->post_status === "publish") {
					$temp_product = $this->get_product_values(wc_get_product($product->ID));
					$data['products'][] = $this->prepare_response_for_collection($temp_product);
				}
			}
			return $data;
		}

		/**
		 * Get all or a collection of products
		 *
		 * @param WP_REST_Request $request Full data about the request.
		 * @return WP_Error|WP_REST_Response
		 */
		public function get_products($request)
		{
			// Get Parameters
			$show_variations = rest_sanitize_boolean($request->get_param('variation'));
			$limit = intval($request->get_param('limit'));
			$page = intval($request->get_param('page'));
			if(!empty($request->get_param('products'))) {
				$product_list = explode(',', (sanitize_text_field($request->get_param('products'))));
				if(is_array($product_list)) {
					foreach($product_list as $key => $field) {
						$product_list[$key] = intval($field);
					}
				}
			}
			if(!empty($request->get_param('slugs'))) {
				$slug_list = explode(',', (sanitize_text_field(urldecode($request->get_param('slugs')))));
			}

			// Check request is valid and update
			$response = $this->check_request($request);
			if(!is_array($response)) {
				$data['Response'] = '';
				$data['Error'] = $response;
				$response_code = 500;
			} else {
				$response_body = $response['body'];
				$response = json_decode($response_body, true);

				if($response['success'] === TRUE && $response['message'] === 'the token is valid') {
					if(!empty($product_list)) {
						$data = $this->get_list_products($product_list);
					} elseif(!empty($slug_list)) {
						$data = $this->get_list_slugs($slug_list);
					} else {
						$data = $this->get_all_products($show_variations, $limit, $page);
					}
					$response_code = 200;
				} else {
					$data['Response'] = $response_body;
					$data['Error'] = $response['error'];
					$response_code = 401;
				}
			}
			$data['Version'] = $this->wcpe_version;
			return new WP_REST_Response($data, $response_code);
		}
	}

	$WC_Products_Extractor = new WC_Products_Extractor;
}
