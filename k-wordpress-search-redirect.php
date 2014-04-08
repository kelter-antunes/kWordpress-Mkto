<?php
$query = $_POST['SearchQuery'];

include '../../../wp-load.php';
include WP_CONTENT_DIR . '/blog/wp-content/plugins/kwordpress-mkto/k-wordpress-mkto.php';

if ( $query != "" ) {
	kwm_syncLeadEvent( "Last Blog Search: " . $query  );
	header( "Location: /blog/?s=" . $query  );
}else {
	header( "Location: /blog/" );

}
exit();
?>
