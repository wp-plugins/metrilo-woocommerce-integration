<?php

 
if ( ! class_exists( 'Metrilo_Woo_Analytics_Integration' ) ) :

class Metrilo_Woo_Analytics_Integration extends WC_Integration {


	private $events_queue = array();
	private $single_item_tracked = false;
	private $has_events_in_cookie = false;
	private $identify_call_data = false;
	private $woo = false;

 
	/**
	 * 
	 * 
	 * Initialization and hooks
	 * 
	 * 
	 */

	public function __construct() {
		global $woocommerce, $metrilo_woo_analytics_integration;

		$this->woo = function_exists('WC') ? WC() : $woocommerce;

		$this->id = 'metrilo-woo-analytics';
		$this->method_title = __( 'Metrilo', 'metrilo-woo-analytics' );
		$this->method_description = __( 'Metrilo offers powerful yet simple CRM & Analytics for WooCommerce and WooCommerce Subscription Stores. Enter your API key to activate analytics tracking.', 'metrilo-woo-analytics' );
 

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();
 
		// Fetch the integration settings
		$this->api_key = $this->get_option('api_key', false);
		$this->api_secret = $this->get_option('api_secret', false);
		$this->ignore_for_roles = $this->get_option('ignore_for_roles', false);
		$this->accept_tracking = true;

		// previous version compatibility - fetch token from Wordpress settings
		if(empty($this->api_key)){
			$this->api_key = $this->get_previous_version_settings_key();
		}
		if(empty($this->api_secret)){
			$this->api_secret = false;
		}

		// ensure correct plugin path
		$this->ensure_path();

		// initiate woocommerce hooks and activities
		add_action('woocommerce_init', array($this, 'on_woocommerce_init'));
 
		// hook to integration settings update
		add_action( 'woocommerce_update_options_integration_' .  $this->id, array($this, 'process_admin_options'));
 
	}

	public function ensure_uid(){
		$this->cbuid = $this->session_get('ensure_cbuid');
		if(!$this->cbuid){
			$this->cbuid = md5(uniqid(rand(), true)) . rand();
			$this->session_set('ensure_cbuid', $this->cbuid);
		}
	}

	public function on_woocommerce_init(){

		// check if API token and Secret are both entered
		$this->check_for_keys();

		// hook to WooCommerce models
		$this->ensure_hooks();

		// process cookie events
		$this->process_cookie_events();

		// ensure identification
		$this->ensure_identify();

		// ensure session identification of visitor
		$this->ensure_uid();

	}

	public function check_for_keys(){
		if(is_admin()){
			if((empty($this->api_key) || empty($this->api_secret)) && empty($_POST['save'])){
				add_action('admin_notices', array($this, 'admin_keys_notice'));
			}
			if(!empty($_POST['save']) && !empty($this->api_key) && !empty($_POST['woocommerce_metrilo-woo-analytics_api_key'])){
				add_action('admin_notices', array($this, 'admin_import_invite'));
			}
		}
	}

	public function admin_keys_notice(){
		if(empty($this->api_key)) $message = 'Almost done! Just enter your Metrilo API key to get started';
		if(empty($this->api_secret)) $message = 'Almost done! Just enter your Metrilo API key and secret';
		echo '<div class="updated"><p>'.$message.' <a href="'.admin_url('admin.php?page=wc-settings&tab=integration').'">here</a></p></div>';
	}

	public function admin_import_invite(){
		echo '<div class="updated"><p>Awesome! Have you tried <a href="'.admin_url('tools.php?page=metrilo-import').'"><strong>importing your existing customers to Metrilo</strong></a>?</p></div>';
	}

	public function ensure_hooks(){

		// general tracking snipper hook
		add_filter('wp_head', array($this, 'render_snippet'));
		add_filter('wp_head', array($this, 'woocommerce_tracking'));

		// background events tracking
		add_action('woocommerce_add_to_cart', array($this, 'add_to_cart'), 10, 6);
		add_action('woocommerce_before_cart_item_quantity_zero', array($this, 'remove_from_cart'), 10);
		add_filter('woocommerce_applied_coupon', array($this, 'applied_coupon'), 10);

		// hook on new order placed
		add_action('woocommerce_checkout_order_processed', array($this, 'new_order_event'), 10);

		// hook on WooCommerce subscriptions renewal
		add_action('woocommerce_subscriptions_renewal_order_created', array($this, 'new_subscription_order_event'), 10, 4);

		// hook on WooCommerce order update
		add_action('woocommerce_order_status_changed', array($this, 'order_status_changed'), 10);

		// cookie clearing actions
		add_action('wp_ajax_metrilo_clear', array($this, 'clear_cookie_events'));
		add_action('wp_ajax_nopriv_metrilo_clear', array($this, 'clear_cookie_events'));
		add_action('wp_ajax_metrilo_chunk_sync', array($this, 'sync_orders_chunk'));

		add_action('admin_menu', array($this, 'setup_admin_pages'));

	}

	public function setup_admin_pages(){
		add_submenu_page('tools.php', 'Export to Metrilo', 'Export to Metrilo', 'export', 'metrilo-import', array($this, 'metrilo_import_page'));
	}

	public function metrilo_import_page(){
		wp_enqueue_script('jquery');
		$metrilo_import = include_once('metrilo_import.php');
		$metrilo_import->prepare_import();
		if(!empty($_GET['import'])){
			$metrilo_import->set_importing_mode(true);
			$metrilo_import->prepare_order_chunks();
		}
		$metrilo_import->output();
	}

	public function sync_orders_chunk(){
		$order_ids = $_REQUEST['orders'];
		if(!empty($order_ids)){
			foreach($order_ids as $order_id){
				$order = new WC_Order($order_id);
				if(!empty($order) && !empty($order->id)){

					// prepare the order data
					$purchase_params = array(
						'order_id' 			=> $order_id, 
						'order_type' 		=> 'import', 
						'order_status' 		=> $this->get_order_status($order), 
						'amount' 			=> $order->get_total(), 
						'shipping_amount' 	=> method_exists($order, 'get_total_shipping') ? $order->get_total_shipping() : $order->get_shipping(),
						'tax_amount'		=> $order->get_total_tax(),
						'items' 			=> array(),
						'shipping_method'	=> $order->get_shipping_method(), 
						'payment_method'	=> $order->payment_method_title
					);
					$call_params = false;

					// check if order has customer IP in it
					$customer_ip = $this->get_order_ip($order_id);
					if($customer_ip){
						$call_params = array('use_ip' => $customer_ip);
					}

					$order_time_in_ms = get_post_time('U', true, $order_id) * 1000;

					$coupons_applied = $order->get_used_coupons();
					if(count($coupons_applied) > 0){
						$purchase_params['coupons'] = $coupons_applied;
					}

					// add the items data to the order
					$order_items = $order->get_items();
					foreach($order_items as $product){
						$product_hash = array('id' => $product['product_id'], 'quantity' => $product['qty'], 'name' => $product['name']);
						if(!empty($product['variation_id'])){
							$variation_data = $this->prepare_variation_data($product['variation_id']);
							$product_hash['option_id'] = $variation_data['id'];
							$product_hash['option_price'] = $variation_data['price'];
						}
						array_push($purchase_params['items'], $product_hash);
					}					
					
					$identity_data = array(
								'email' 		=> get_post_meta($order->id, '_billing_email', true),
								'first_name' 	=> get_post_meta($order->id, '_billing_first_name', true),
								'last_name' 	=> get_post_meta($order->id, '_billing_last_name', true),
								'name'			=> get_post_meta($order->id, '_billing_first_name', true) . ' ' . get_post_meta($order->id, '_billing_last_name', true),
					);

					$this->send_api_call($identity_data['email'], 'order', $purchase_params, $identity_data, $order_time_in_ms, $call_params);

				}
			}
		}

		return true;
	}

	public function ensure_path(){
		define('METRILO_PLUGIN_PATH', dirname(__FILE__));
	}

	public function ensure_identify(){
		// if user is logged in
		if( !is_admin() && is_user_logged_in() && !( $this->session_get( $this->get_identify_cookie_name() ) ) ){
			$user = wp_get_current_user();
			$this->identify_call_data = array('id' => $user->user_email, 'params' => array('email' => $user->user_email, 'name' => $user->display_name));
			if($user->user_firstname!= '' && $user->user_lastname){
				$this->identify_call_data['params']['first_name'] = $user->user_firstname;
				$this->identify_call_data['params']['last_name'] = $user->user_lastname;
			}
			$this->session_set($this->get_identify_cookie_name(), 'true');
		}

	}	


	/**
	 * 
	 * 
	 * Events tracking methods, event hooks
	 * 
	 * 
	 */


	public function woocommerce_tracking(){
		// check if woocommerce is installed
		if(class_exists('WooCommerce')){
			/** check certain tracking scenarios **/

			// if visitor is viewing product
			if(!$this->single_item_tracked && is_product()){
				$product = get_product(get_queried_object_id());
				$this->put_event_in_queue('track', 'view_product', $this->prepare_product_hash($product));
				$this->single_item_tracked = true;
			}

			// if visitor is viewing product category
			if(!$this->single_item_tracked && is_product_category()){
				$this->put_event_in_queue('track', 'view_category', $this->prepare_category_hash(get_queried_object()));
				$this->single_item_tracked = true;
			}

			// if visitor is viewing shopping cart page
			if(!$this->single_item_tracked && is_cart()){
				$this->put_event_in_queue('track', 'view_cart', array());
				$this->single_item_tracked = true;
			}
			// if visitor is anywhere in the checkout process
			if(!$this->single_item_tracked && is_order_received_page()){

				$this->put_event_in_queue('track', 'pageview', 'Thank You');
				$this->single_item_tracked = true;

			}elseif(!$this->single_item_tracked && function_exists('is_checkout_pay_page') && is_checkout_pay_page()){
				$this->put_event_in_queue('track', 'checkout_payment', array());
				$this->single_item_tracked = true;
			}elseif(!$this->single_item_tracked && is_checkout()){
				$this->put_event_in_queue('track', 'checkout_start', array());
				$this->single_item_tracked = true;
			}
		}

		// ** GENERIC WordPress tracking - doesn't require WooCommerce in order to work **//

		// if visitor is viewing homepage or any text page
		if(!$this->single_item_tracked && is_front_page()){
			$this->put_event_in_queue('track', 'pageview', 'Homepage');
			$this->single_item_tracked = true;
		}elseif(!$this->single_item_tracked && is_page()){
			$this->put_event_in_queue('track', 'pageview', get_the_title());
			$this->single_item_tracked = true;
		}

		// if visitor is viewing post
		if(!$this->single_item_tracked && is_single()){
			$post_id = get_the_id();
			$this->put_event_in_queue('track', 'view_article', array('id' => $post_id, 'name' => get_the_title(), 'url' => get_permalink($post_id)));
			$this->single_item_tracked = true;
		}

		// if nothing else is tracked - send pageview event
		if(!$this->single_item_tracked){
			$this->put_event_in_queue('pageview');
		}

		// check if there are events in the queue to be sent to Metrilo
		if(count($this->events_queue) > 0) $this->render_events();
		if($this->identify_call_data !== false) $this->render_identify();
	}

	public function prepare_product_hash($product, $variation_id = false, $variation = false){
		$product_hash = array(
			'id'			=> $product->id, 
			'name'			=> $product->get_title(),
			'price'			=> $product->get_price(),
			'url'			=> get_permalink($product->id)
		);
		if($variation_id){
			$variation_data = $this->prepare_variation_data($variation_id, $variation);
			$product_hash['option_id'] = $variation_data['id'];
			$product_hash['option_name'] = $variation_data['name'];
			$product_hash['option_price'] = $variation_data['price'];
		}
		// fetch image URL
		$image_id = get_post_thumbnail_id($product->id);
		$image = get_post($image_id);
		if($image && $image->guid) $product_hash['image_url'] = $image->guid;

		// fetch the categories
		$categories = wp_get_post_terms($product->id, 'product_cat');
		if(!empty($categories)){
			$categories_list = array();
			foreach($categories as $cat){
				array_push($categories_list, array('id' => $cat->term_id, 'name' => $cat->name));
			}
			if(!empty($categories_list)) $product_hash['categories'] = $categories_list;
		}

		// return 
		return $product_hash;
	}

	public function prepare_category_hash($category){
		$category_hash = array(
			'id'	=>	$category->term_id, 
			'name'	=> 	$category->name
		);
		return $category_hash;
	}

	public function put_event_in_queue($method, $event = '', $params = array()){
		array_push($this->events_queue, $this->prepare_event_for_queue($method, $event, $params));
	}

	public function put_event_in_cookie_queue($method, $event, $params){
		$this->add_item_to_cookie($this->prepare_event_for_queue($method, $event, $params));
	}

	public function prepare_event_for_queue($method, $event, $params){
		return array('method' => $method, 'event' => $event, 'params' => $params);
	}

	public function send_api_call($ident, $event, $params, $identity_data = false, $time = false, $call_params = false){

		if(!empty($this->api_key) && !empty($this->api_secret)){
			$this->prepare_secret_call_hash($ident, $event, $params, $identity_data, $time, $call_params);
		}

	}

	private function prepare_secret_call_hash($ident, $event, $params, $identity_data = false, $time = false, $call_params = false){

		// prepare API call params

		try {

			$call = array(
				'event_type'		=> $event, 
				'params'			=> $params, 
				'uid'				=> $ident, 
				'token'				=> $this->api_key
			);
			if($time){
				$call['time'] = $time;
			}

			// check for special parameters to include in the API call
			if($call_params){
				if($call_params['use_ip']){
					$call['use_ip'] = $call_params['use_ip'];
				}
			}



			// put identity data in call if available
			if($identity_data){
				$call['identity'] = $identity_data;
			}

			// sort for salting and prepare base64
			ksort($call);
			$based_call = base64_encode(json_encode($call));
			$signature = md5($based_call.$this->api_secret);

			// generate API call end point and call it
			$end_point = 'http://p.metrilo.com/t?s='.$signature.'&hs='.$based_call;
			$c = wp_remote_get($end_point);

		} catch (Exception $e){

		}

	}

	public function add_to_cart($cart_item_key, $product_id, $quantity, $variation_id = false, $variation = false, $cart_item_data = false){
		$product = get_product($product_id);
		$this->put_event_in_cookie_queue('track', 'add_to_cart', $this->prepare_product_hash($product, $variation_id, $variation));
		$items = $this->get_items_in_cookie();
	}

	public function remove_from_cart($key_id){
		if (!is_object($this->woo->cart)) {
			return true;
		}		
		$cart_items = $this->woo->cart->get_cart();
		$removed_cart_item = isset($cart_items[$key_id]) ? $cart_items[$key_id] : false;
		if($removed_cart_item){
			$event_params = array('id' => $removed_cart_item['product_id']);
			if(!empty($removed_cart_item['variation_id'])){
				$event_params['option_id'] = $removed_cart_item['variation_id'];
			}
			$this->put_event_in_cookie_queue('track', 'remove_from_cart', $event_params);
		}
	}

	public function prepare_variation_data($variation_id, $variation = false){
		// prepare variation data array
		$variation_data = array('id' => $variation_id, 'name' => '', 'price' => '');

		// prepare variation name if $variation is provided as argument
		if($variation){
			$variation_attribute_count = 0;
			foreach($variation as $attr => $value){
				$variation_data['name'] = $variation_data['name'] . ($variation_attribute_count == 0 ? '' : ', ') . $value;
				$variation_attribute_count++;
			}
		}

		// get variation price from object
		$variation_obj = new WC_Product_Variation($variation_id);
		$variation_data['price'] = $variation_obj->price;

		// return
		return $variation_data;
	}

	public function applied_coupon($coupon_code){
		$this->put_event_in_queue('track', 'applied_coupon', $coupon_code);	
	}

	public function new_order_event($order_id){

		// fetch the order
		$order = new WC_Order($order_id);

		// identify user - put identify data in cookie
		$this->identify_call_data = array(
			'id'		=> get_post_meta($order_id, '_billing_email', true),
			'params'	=> array(
						'email' 		=> get_post_meta($order_id, '_billing_email', true),
						'first_name' 	=> get_post_meta($order_id, '_billing_first_name', true),
						'last_name' 	=> get_post_meta($order_id, '_billing_last_name', true),
						'name'			=> get_post_meta($order_id, '_billing_first_name', true) . ' ' . get_post_meta($order_id, '_billing_last_name', true),
					)
		);

		// prepare the order data
		$purchase_params = array(
			'order_id' 			=> $order_id, 
			'order_type'		=> 'purchase',
			'order_status'		=> $this->get_order_status($order),
			'amount' 			=> $order->get_total(),
			'shipping_amount' 	=> method_exists($order, 'get_total_shipping') ? $order->get_total_shipping() : $order->get_shipping(),
			'tax_amount'		=> $order->get_total_tax(),
			'items' 			=> array(),
			'shipping_method'	=> $order->get_shipping_method(), 
			'payment_method'	=> $order->payment_method_title
		);

		$coupons_applied = $order->get_used_coupons();
		if(count($coupons_applied) > 0){
			$purchase_params['coupons'] = $coupons_applied;
		}

		// add the items data to the order
		$order_items = $order->get_items();
		foreach($order_items as $product){
			$product_hash = array('id' => $product['product_id'], 'quantity' => $product['qty'], 'name' => $product['name']);
			if(!empty($product['variation_id'])){
				$variation_data = $this->prepare_variation_data($product['variation_id']);
				$product_hash['option_id'] = $variation_data['id'];
				$product_hash['option_price'] = $variation_data['price'];
			}
			array_push($purchase_params['items'], $product_hash);
		}

		// put the order and identify data in cookies
		$this->put_event_in_cookie_queue('track', 'order', $purchase_params);
		$this->session_set($this->get_do_identify_cookie_name(), json_encode($this->identify_call_data, true));

	}

	public function new_subscription_order_event($order, $original_order, $product_id, $new_order_role){

		try {



			$purchase_params = array(
				'order_id' 			=> $order->id, 
				'order_type'		=> 'renewal',
				'meta_source'		=> '_renewal',
				'order_status'		=> $this->get_order_status($order),
				'amount' 			=> $order->get_total(), 
				'shipping_amount' 	=> method_exists($order, 'get_total_shipping') ? $order->get_total_shipping() : $order->get_shipping(),
				'tax_amount'		=> $order->get_total_tax(),
				'items' 			=> array(),
				'shipping_method'	=> $order->get_shipping_method(), 
				'payment_method'	=> $order->payment_method_title
			);

			$identity_data = array(
						'email' 		=> get_post_meta($order->id, '_billing_email', true),
						'first_name' 	=> get_post_meta($order->id, '_billing_first_name', true),
						'last_name' 	=> get_post_meta($order->id, '_billing_last_name', true),
						'name'			=> get_post_meta($order->id, '_billing_first_name', true) . ' ' . get_post_meta($order->id, '_billing_last_name', true),
			);


			$product = get_product($product_id);

			// prepare product data
			$product_data = $this->prepare_product_hash($product);
			$product_data['quantity'] = 1;

			$purchase_params['items'] = array($product_data);

			$this->send_api_call($identity_data['email'], 'order', $purchase_params, $identity_data);

		}catch (Exception $e){

		}

	}

	public function order_status_changed($order_id, $old_status = false, $new_status = false){
		try {

				$order = new WC_Order($order_id);

				// prepare the order data
				$purchase_params = array(
					'order_id' 			=> $order_id, 
					'order_status' 		=> $this->get_order_status($order), 
					'amount' 			=> $order->get_total(), 
					'shipping_amount' 	=> method_exists($order, 'get_total_shipping') ? $order->get_total_shipping() : $order->get_shipping(),
					'tax_amount'		=> $order->get_total_tax(),
					'items' 			=> array(),
					'shipping_method'	=> $order->get_shipping_method(), 
					'payment_method'	=> $order->payment_method_title
				);
				$call_params = false;

				// check if order has customer IP in it
				$customer_ip = $this->get_order_ip($order_id);
				if($customer_ip){
					$call_params = array('use_ip' => $customer_ip);
				}

				$order_time_in_ms = get_post_time('U', true, $order_id) * 1000;

				$coupons_applied = $order->get_used_coupons();
				if(count($coupons_applied) > 0){
					$purchase_params['coupons'] = $coupons_applied;
				}

				// add the items data to the order
				$order_items = $order->get_items();
				foreach($order_items as $product){
					$product_hash = array('id' => $product['product_id'], 'quantity' => $product['qty'], 'name' => $product['name']);
					if(!empty($product['variation_id'])){
						$variation_data = $this->prepare_variation_data($product['variation_id']);
						$product_hash['option_id'] = $variation_data['id'];
						$product_hash['option_price'] = $variation_data['price'];
					}
					array_push($purchase_params['items'], $product_hash);
				}					
				
				$identity_data = array(
							'email' 		=> get_post_meta($order->id, '_billing_email', true),
							'first_name' 	=> get_post_meta($order->id, '_billing_first_name', true),
							'last_name' 	=> get_post_meta($order->id, '_billing_last_name', true),
							'name'			=> get_post_meta($order->id, '_billing_first_name', true) . ' ' . get_post_meta($order->id, '_billing_last_name', true),
				);

				$this->send_api_call($identity_data['email'], 'order', $purchase_params, $identity_data, $order_time_in_ms, $call_params);

			


		}catch(Exeption $e){

		}
	}

	public function get_order_status($order_object){
		if(method_exists($order_object, 'get_status')){
			return $order_object->get_status();
		}else{
			if(property_exists($order_object, 'status')){
				return $order_object->status;
			}
		}
	}

	/**
	 * 
	 * 
	 * Tracking code rendering
	 * 
	 * 
	 */


	public function render_events(){
		include_once(METRILO_PLUGIN_PATH.'/render_tracking_events.php');
	}

	public function render_identify(){
		include_once(METRILO_PLUGIN_PATH.'/render_identify.php');
	}

	public function render_snippet(){
		// check if we should track data for this user (if user is available)
		if( !is_admin() && is_user_logged_in()){
			$user = wp_get_current_user();
			if($user->roles && $this->ignore_for_roles){
				foreach($user->roles as $r){
					if(in_array($r, $this->ignore_for_roles)){
						$this->accept_tracking = false;
					}
				}
			}
		}

		// render the JS tracking code
		include_once(METRILO_PLUGIN_PATH.'/js.php');
		
	} 


	/**
	 * 
	 * 
	 * Session and cookie handling
	 * 
	 * 
	 */

	public function session_get($k){
		if(!is_object($this->woo->session)){
			return isset($_COOKIE[$k]) ? $_COOKIE[$k] : false;
		}
		return $this->woo->session->get($k);
	}

	public function session_set($k, $v){
		if(!is_object($this->woo->session)){
			@setcookie($k, $v, time() + 43200, COOKIEPATH, COOKIE_DOMAIN);
			$_COOKIE[$k] = $v;
			return true;
		}
		return $this->woo->session->set($k, $v);
	}

	public function add_item_to_cookie($data){
		$items = $this->get_items_in_cookie();
		if(empty($items)) $items = array();
		array_push($items, $data);
		$encoded_items = json_encode($items, true);
		$this->session_set($this->get_cookie_name(), $encoded_items);
	}

	public function get_items_in_cookie(){
		$items = array();
		$data = $this->session_get($this->get_cookie_name());
		if(!empty($data)) $items = json_decode(stripslashes($data), true);
		return $items;
	}

	public function get_identify_data_in_cookie(){
		$identify = array();
		$data = $this->session_get($this->get_do_identify_cookie_name());
		if(!empty($data)) $identify = json_decode(stripslashes($data), true);
		return $identify;
	}

	public function clear_items_in_cookie(){
		$this->session_set($this->get_cookie_name(), json_encode(array(), true));
		$this->session_set($this->get_do_identify_cookie_name(), json_encode(array(), true));
	}

	public function get_order_ip($order_id){
		$ip_address = get_post_meta($order_id, '_customer_ip_address', true);
		if(strpos($ip_address, '.') !== false){
			return $ip_address;
		}
		return false;
	}

	private function get_cookie_name(){
		return 'metriloqueue_' . COOKIEHASH;
	}

	private function get_identify_cookie_name(){
		return 'metriloid_' . COOKIEHASH;
	}

	private function get_do_identify_cookie_name(){
		return 'metrilodoid_' . COOKIEHASH;
	}


	public function clear_cookie_events(){
		$this->clear_items_in_cookie();
		wp_send_json_success();
	}

	public function process_cookie_events(){
		$items = $this->get_items_in_cookie();
		if(count($items) > 0){
			$this->has_events_in_cookie = true;
			foreach($items as $event){
				$this->put_event_in_queue($event['method'], $event['event'], $event['params']);
			}
		}

		$identify_data = $this->get_identify_data_in_cookie();
		if(!empty($identify_data)) $this->identify_call_data = $identify_data;

	}


	/** 
	 * Settings compatibility with previous versin - fetch api key from WP options pool
	 */

	public function get_previous_version_settings_key(){
		$api_key = false;

		// fetch settings
		$settings = get_option('metrilo_woo_analytics');
		if(!empty($settings) && !empty($settings['api_token'])){
			$api_key = $settings['api_token'];
		}
		return $api_key;
	}
 
	/**
	 * Initialize integration settings form fields.
	 */
	public function init_form_fields() {

		// initiate possible user roles from settings
		$possible_ignore_roles = false;
		
		if(is_admin()){
			global $wp_roles;
			$possible_ignore_roles = array();
			foreach($wp_roles->roles as $role => $stuff){
				$possible_ignore_roles[$role] = $stuff['name'];
			}
		}

		$this->form_fields = array(
			'api_key' => array(
				'title'             => __( 'API Token', 'metrilo-woo-analytics' ),
				'type'              => 'text',
				'description'       => __( 'Enter your Metrilo API token. You can find it under "Settings" in your Metrilo account.<br /> Don\'t have one? <a href="https://www.metrilo.com/signup?ref=woointegration" target="_blank">Sign-up for free</a> now, it only takes a few seconds.', 'metrilo-woo-analytics' ),
				'desc_tip'          => false,
				'default'           => ''
			),
			'api_secret' => array(
				'title'             => __( 'API Secret Key <span style="color: green;">(new)</span>', 'metrilo-woo-analytics' ),
				'type'              => 'text',
				'description'       => __( '<strong style="color: green;">Important:</strong> Enter your Metrilo API secret key to activate Subscriptions tracking and to be able to sync with Metrilo', 'metrilo-woo-analytics' ),
				'desc_tip'          => false,
				'default'           => ''
			)
		);

		if($possible_ignore_roles){
			$this->form_fields['ignore_for_roles'] = array(
				'title'             => __( 'Ignore tracking for roles', 'metrilo-woo-analytics' ),
				'type'              => 'multiselect',
				'description'       => __( 'If you check any of the roles, tracking data will be ignored for WP users with this role', 'metrilo-woo-analytics' ),
				'desc_tip'          => false,
				'default'           => '', 
				'options'			=> $possible_ignore_roles
			);
		}
	}
 
}



endif;