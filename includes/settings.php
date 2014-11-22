<div class="wrap">
    <h2><?php _e( 'Metrilo Settings', 'metrilo' ); ?></h2>
    <form method="post" action="options.php">
      <?php
        settings_fields( 'metrilo' );
        do_settings_sections( 'metrilo' );
        submit_button();
      ?>
    </form>
</div>