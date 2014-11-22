<script type="text/javascript">
<?php foreach($this->events_queue as $event): ?>
	<?php if($event['method'] == 'track'): ?>
	metrilo.event("<?= $event['event']; ?>", <?= json_encode($event['params']); ?>);
	<?php endif; ?>
	<?php if($event['method'] == 'purchase'): ?>
	metrilo.purchase(<?= json_encode($event['params']); ?>);
	<?php endif; ?>
	<?php if($event['method'] == 'pageview'): ?>
	metrilo.pageview();
	<?php endif; ?>
<?php endforeach; ?>
</script>
<?php if ($this->has_events_in_cookie): ?>
	<script type="text/javascript">
	jQuery(document).ready(function($) {
		$.post("<?= admin_url('admin-ajax.php'); ?>", {'action': 'metrilo_clear'}, function(response) {});
	});
	</script>
<?php endif; ?>