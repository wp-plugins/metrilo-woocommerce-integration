<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'Metrilo_Import' ) ) :


class Metrilo_Import {


	private $orders_list = array();
	private $orders_total = 0;
	private $importing = false;

	public function prepare_import(){

		// prepare to fetch all orders
		$args = array(
                    'post_type' 		=> 'shop_order',
                    'post_status' 		=> 'publish',
                    'posts_per_page' 	=> -1, 
                    'order'				=> 'ASC'
                );
		$loop = new WP_Query( $args );

		// fetsh all order IDs and push to array

		while ( $loop->have_posts() ) : 
			$loop->the_post();
            $order_id = $loop->post->ID;
            array_push($this->orders_list, $order_id);
        endwhile;

        // prepare meta data for import
        $this->orders_total = count($this->orders_list);

	}

	public function set_importing_mode($mode){
		$this->importing = $mode;
	}

	public function prepare_order_chunks(){
		$chunks = array();
		$current_chunk = 0;
		foreach($this->orders_list as $order_id){
			if(!isset($chunks[$current_chunk])){
				$chunks[$current_chunk] = array();
			}
			$chunks[$current_chunk][] = $order_id;
			if(count($chunks[$current_chunk]) >= 10){
				$current_chunk++;
			}
		}
		$this->chunks = $chunks;
		$this->total_chunks = count($chunks);
	}

	public function output(){
		include_once( 'views/metrilo_import_view.php' );
	}

}





endif;

return new Metrilo_Import();