<div class="welcome-panel">
	<div style="float: left; width: 120px; text-align: center;">
		<img src="https://www.metrilo.com/assets/invoice_logo.png" />
	</div>
	<div style="float: left;">
	<h3>Importing your orders and customers to Metrilo</h3>
	<p>
		This tool syncs all your orders and customers to Metrilo and can take <strong>up to a few minutes</strong> to complete. <br />
	  	Make sure you do not close this page while importing. Coffee, maybe?
	</p>
	<?php if($this->importing): ?>
		<script type="text/javascript">
		jQuery(document).ready(function($){
			
			var metrilo_chunks = <?php echo json_encode($this->chunks); ?>;
			var total_chunks = <?php echo count($this->chunks); ?>;
			var chunk_percentage = 100;
			if(total_chunks > 0){
				var chunk_percentage = (100 / total_chunks);
			}
			var sync_chunk = function(chunk_id){
				progress_percents = Math.round(chunk_id * chunk_percentage);
				update_importing_message('Importing... '+progress_percents+'% done');

				var order_ids = metrilo_chunks[chunk_id];
				$.post("<?php echo admin_url('admin-ajax.php'); ?>", {'action': 'metrilo_chunk_sync', 'orders': order_ids}, function(response) {

					new_chunk_id = chunk_id + 1;
					if(metrilo_chunks[new_chunk_id] != undefined){
						setTimeout(function(){
							sync_chunk(new_chunk_id);
						}, 2000);
					}else{
						update_importing_message("<span style='color: green;'>Done! Please expect up to 30 minutes for your historical data to appear in Metrilo.</span>");
					}

				});

			}

			var update_importing_message = function(message){
				$('#metrilo_import_status').html(message);
			}

			sync_chunk(0);

		});
		</script>
		<strong id="metrilo_import_status">Importing...</strong>
	<?php else: ?>
		<a href="<?php echo admin_url('tools.php?page=metrilo-import&import=1') ?>" class="button"><strong>Sync <?php echo $this->orders_total; ?> orders now</strong></a>
	<?php endif; ?>
	</div>
<br style="clear: both;" />
<br />
</div>
<div style="color: #888; font-size: 11px; padding: 5px;">
	If you encounter any issues, let us know at <a href="mailto:support@metrilo.com">support@metrilo.com</a>. We'll be happy to assist you! 
</div>
