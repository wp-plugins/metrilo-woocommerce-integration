<div class="welcome-panel">
	<div style="float: left; width: 120px; text-align: center;">
		<img src="https://www.metrilo.com/assets/invoice_logo.png" />
	</div>
	<div style="float: left;">
	<h3>Importing your orders and customers to Metrilo</h3>
	<p>
		This tool helps you sync all your orders (and their respective customers) to Metrilo and can take <strong>up to 20 minutes</strong> to complete. <br />
		It will not affect your website's performance at all since it sends your orders to your Metrilo account in small chunks.  <br /><br />
	  	Make sure to <strong>not close this page</strong> while importing. Coffee, maybe?
	</p>
	<?php if($this->importing): ?>
		<script type="text/javascript">
		jQuery(document).ready(function($){

			var chunk_pages = <?php echo $this->chunk_pages; ?>;
			var chunk_percentage = 100;
			if(chunk_pages > 0){
				var chunk_percentage = (100 / chunk_pages);
			}
			var sync_chunk = function(chunk_page){
				progress_percents = Math.round(chunk_page * chunk_percentage);
				update_importing_message('Please wait... '+progress_percents+'% done');

				$.post("<?php echo admin_url('admin-ajax.php'); ?>", {'action': 'metrilo_chunk_sync', 'chunk_page': chunk_page}, function(response) {

					new_chunk_page = chunk_page + 1;
					if(new_chunk_page <= chunk_pages){
						setTimeout(function(){
							sync_chunk(new_chunk_page);
						}, 900);
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
		<strong id="metrilo_import_status">Syncing...</strong>
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
