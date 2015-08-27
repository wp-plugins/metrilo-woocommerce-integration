<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'Metrilo_Import' ) ) :


class Metrilo_Import {


	private $orders_list = array();
	private $orders_total = 0;
	private $importing = false;

	public function prepare_import(){

		global $wpdb;

    // prepare how many orders should be imported
    $this->orders_total = (int)$wpdb->get_var("select count(id) from {$wpdb->posts} where post_type = 'shop_order' order by id asc");

	}

	public function set_importing_mode($mode){
		$this->importing = $mode;
	}

	public function prepare_order_chunks($orders_per_chunk = 10){

		$this->chunk_pages = ceil($this->orders_total / $orders_per_chunk);

	}

	public function output(){
		include_once( 'views/metrilo_import_view.php' );
	}

}





endif;

return new Metrilo_Import();