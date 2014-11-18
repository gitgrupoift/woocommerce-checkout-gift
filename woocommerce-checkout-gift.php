<?php
/*
    Plugin Name: WooCommerce Checkout Gift
    Version: 0.1
    Description: Granting gift to customer who's purchase passes particular amount limit
    Author: Fikri Rasyid
    Author URI: http://fikrirasyid.com
*/
/*
    Copyright 2014 Fikri Rasyid
    Developed by Fikri Rasyid (fikrirasyid@gmail.com)
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Check if WooCommerce is active
 **/
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    /**
     * If the plugin is called before woocommerce, we need to include it first
     */
    if( !class_exists( 'Woocommerce' ) )
        include_once( ABSPATH . 'wp-content/plugins/woocommerce/woocommerce.php' );
	
	class Woocommerce_Checkout_Gift{

		var $plugin_url;
		var $plugin_dir;
		var $current_time;

		/**
		 * Init the method
		 */
		function __construct(){
			$this->plugin_url = untrailingslashit( plugins_url( '/', __FILE__ ) );
			$this->plugin_dir = plugin_dir_path( __FILE__ );
			$this->current_time = current_time( 'timestamp' );

			// Enqueueing scripts
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

			// Adding settings to Dashboard > WooCommerce > Settings > Checkout
			add_filter( 'woocommerce_payment_gateways_settings', 			array( $this, 'settings' ) );

			// Providing endpoint for product autocomplete
			add_action( 'wp_ajax_woocommerce_checkout_gift_get_products', 	array( $this, 'get_products_endpoint' ) );

			// Adding gift to cart
			add_action( 'woocommerce_checkout_process', 					array( $this, 'add_gift_to_cart' ) );

			// Set price as zero price for gift
			add_action( 'woocommerce_calculate_totals', 					array( $this, 'set_gift_price' ) );
		}

		/**
		 * Register and enqueue script on dashboard
		 * 
		 * @access public
		 * @return void
		 */
		public function admin_scripts(){
			if( ! function_exists( 'get_current_screen' ) )
				return;

			// Get current screen estate
			$screen = get_current_screen();

			// Only enqueue the script on bulk sale screen
			if( 'woocommerce_page_wc-settings' == $screen->id ){
				wp_enqueue_script( 'woocommerce-checkout-gift', $this->plugin_url . '/js/woocommerce-checkout-gift-admin.js', array( 'jquery', 'ajax-chosen' ), '0.1' );
			}
		}

		/**
		 * Adding checkout settings on Dashboard > WooCommerce > Settings > Checkout tab
		 * 
		 * @access public
		 * @param array  	settings
		 * @return array 	modified settings
		 */
		public function settings( $settings ){	

			$recent_products = $this->get_products( false, 'init' );

			$settings[] = array( 
				'title' => __( 'Checkout Gift', 'woocommerce' ), 
				'type' => 'title', 
				'desc' => __( 'Grant your user a gift if his/her purchase amout passes the limit defined below', 'woocommerce' ), 
				'id' => 'checkout_gift_options' 
			);

			$settings[] = array(
				'title'    => __( 'Purchase Limit', 'woocommerce' ),
				'desc'     => __( 'Grant user a gift if his/her amout of purchase passes this limit. To disable gift, set the value to 0', 'woocommerce' ),
				'id'       => 'woocommerce_checkout_gift_purchase_limit',
				'type'     => 'number',
				'default'  => 0,
				'desc_tip' => true,
			);

			$settings[] = array(
				'title'             => __( 'Select Product as Gift', 'woocommerce' ),
				'type'              => 'select',
				'id'				=> 'woocommerce_checkout_gift_product',
				'class'				=> 'woocommerce-checkout-gift-product',
				'default'           => __( 'Select Gift' ),
				'desc'      		=> __( 'Choose product to be given', 'woocommerce' ),
				'options'           => $recent_products,
				'desc_tip'          => true,
				'custom_attributes' => array(
					'data-placeholder' => __( 'Select Gift', 'woocommerce' )
				)
			);	

			$settings[] = array( 
				'type' => 'sectionend', 
				'id' => 'checkout_gift_options' 
			);			

			return $settings;
		}

		/**
		 * Get products
		 * 
		 * @access private
		 * @param string 	search term
		 * @return array
		 */
		private function get_products( $term = false, $mode = false ){
			$args = array(
				'post_status' 	=> 'publish',
				'post_type'		=> 'product',
				'posts_per_page'=> 10
			);

			if( $term ){
				$args['s'] = sanitize_text_field( $term );
			}

			$products = get_posts( $args );

			if( 'init' == $mode ){
				$default = get_option( 'woocommerce_checkout_gift_product' );

				if( $default && '' != $default ){
					$post 							= get_post( $default );
					$default_product 				= new stdClass();
					$default_product->ID 			= $default;
					$default_product->post_title 	= $post->post_title;

					$products[] = $default_product;
				}
			}			

			return $this->_prepare_products( $products );
		}

		/**
		 * Prepare products object to be displayed as key => value
		 * 
		 * @access private
		 * @param obj
		 * @param array
		 */
		private function _prepare_products( $posts = array(), $mode = 'init' ){
		
			$products = array();

			if( 'init' == $mode ){
				$products[''] = __( 'Select product as gift', 'wooocommerce-checkout-gift' );
			}

			if( ! empty( $posts ) ){

				foreach ($posts as $post ) {

					switch ( $mode ) {
						case 'ajax':
							$products[] = array( 'value' => $post->ID, 'text' => $post->post_title );
							break;
						
						default:
							$products[$post->ID] = $post->post_title;
							break;
					}

				}

			}

			return $products;
		}

		/**
		 * Get products endpoint for AJAX powered select product dropdown
		 * 
		 * @access public
		 * @return void
		 */
		public function get_products_endpoint(){

			/**
			 * Get term
			 */
			if( isset( $_GET['term'] ) ){
				$term = $_GET['term'];
			} else {
				$term = false;
			}

			/**
			 * Get product list
			 */
			$products = $this->get_products( $term, 'ajax' );

			/**
			 * Output product as json
			 */
			echo json_encode( $products );

			die();
		}

		/**
		 * Get minimum amout of purchase for granting gift
		 * 
		 * @access private
		 * @return int
		 */
		private function minimum_gift_purchase(){
			$minimum_gift_purchase = intval( get_option( 'woocommerce_checkout_gift_purchase_limit', 0 ) );

			return $minimum_gift_purchase;
		}

		/**
		 * Get product ID of gift
		 * 
		 * @access private
		 * @return int
		 */
		private function gift_id(){
			$gift_id = get_option( 'woocommerce_checkout_gift_product', false );

			return $gift_id;
		}

		/**
		 * Conditional method for checking current cart's status for gift
		 * 
		 * @access private
		 * @return bool
		 */
		private function is_eligible_for_gift(){
			if( 0 != $this->minimum_gift_purchase() && WC()->cart->subtotal_ex_tax > $this->minimum_gift_purchase() ){
				return true;
			} else {
				return false;
			}
		}

		/**
		 * Adding gift to cart during checkout process if the amount of purchase passes the gift minimum limit
		 * 
		 * @access public
		 * @return void
		 */
		public function add_gift_to_cart(){

			/**
			 * Check if minimum gift purchase value is set and current cart passes it
			 */
			if( $this->is_eligible_for_gift() ){
				WC()->cart->add_to_cart( $this->gift_id() );
			}
		}

		/**
		 * Change the gift price to free
		 * 
		 * @access public
		 * @return void
		 */
		public function set_gift_price( $cart ){
			add_filter( 'woocommerce_get_price', array( $this, 'gift_price' ), 10, 2 );
		}

		/**
		 * Set gift price to zero upon checkout
		 * 
		 * @access public
		 * @return int|bool
		 */
		public function gift_price( $price, $product ){
			if( $this->gift_id() == $product->id && defined('WOOCOMMERCE_CHECKOUT') ){
				return 0;				
			} else {
				return $price;
			}
		}

	}	
	new Woocommerce_Checkout_Gift;
}