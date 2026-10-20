<?php
// Test-only: simulates a theme/plugin that prints a second meta description.
add_action( 'wp_head', function () { if ( get_option( 'sp_test_dup' ) ) { echo '<meta name="description" content="Theme duplicate">'; } }, 5 );
