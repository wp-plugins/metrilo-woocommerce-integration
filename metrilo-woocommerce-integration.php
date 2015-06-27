<?php
/**
 * Plugin Name: Metrilo for WooCommerce
 * Plugin URI: https://www.metrilo.com/woocommerce-analytics
 * Description: One-click WooCommerce integration with Metrilo eCommerce Analytics
 * Version: 1.1.7
 * Author: Metrilo
 * Author URI: https://www.metrilo.com/?ref=wpplugin
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) { 
    exit; // Exit if accessed directly
}

if ( ! class_exists( 'Metrilo_Woo_Analytics' ) ) :

class Metrilo_Woo_Analytics {


	public function __construct() {
		add_action('plugins_loaded', array($this, 'init'));
	}

	public function init(){
		// Checks if WooCommerce is installed and activated.
		if ( class_exists( 'WC_Integration' ) ) {
			// Include our integration class.
			include_once 'includes/integration.php';
 
			// Register the integration.
			add_filter( 'woocommerce_integrations', array( $this, 'add_integration' ) );
		} else {
			// throw an admin error if you like
		}		
	}

	public function add_integration($integrations){
		$integrations[] = 'Metrilo_Woo_Analytics_Integration';
		return $integrations;
	}

}

$MetriloWooAnalytics = new Metrilo_Woo_Analytics(__FILE__);


endif;

?>