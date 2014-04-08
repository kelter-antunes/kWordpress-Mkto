<?php


include '../../../wp-load.php';
include WP_CONTENT_DIR . '/blog/wp-content/plugins/kwordpress-mkto/k-wordpress-mkto.php';

mkto_scheduleCampaign(1704);

// //kwm_syncLeadEvent( "Blog Search: Testing" );

// //echo '123';

//  $post = get_post( 1700 );

// // $title = $post->post_title;

//  echo $post->post_title . '</br>' . $post->post_date . '</br>' . $post->post_date_gmt. '</br></br></br></br></br>' ;



//  $date = new DateTime($post->post_date, new DateTimeZone('America/Los_Angeles'));
//  echo $date->format(DATE_W3C) . "\n";
?>