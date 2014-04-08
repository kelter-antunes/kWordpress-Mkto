<?php
/**
 * Plugin Name: kWordpress-Mkto
 * Plugin URI: https://github.com/kelter-antunes/kWordpress-Mkto
 * Description: Simple but awesome Marketo integration with Wordpress. It wraps the scheduleCampaign() Api call, to send email to an email subscribers smart list.
 * Version: 0.3
 * Author: Miguel Antunes
 * Author URI: https://github.com/kelter-antunes
 * License: GPL2
 * Copyright 2014 Miguel Antunes  (email : miguel.aka.kelter@gmail.com)
 */
/*
    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
    add_action( 'admin_menu', 'kwm_admin_menu' );
    add_action( 'admin_init', 'kwm_register_settings' );
    add_action( 'wp_head', 'kwm_call_code' );


    add_action( 'admin_init', 'mkto_save_post_add_meta_box' );
    add_action( 'save_post', 'mkto_save_post_meta_box_save' );

/**
 * Load meta box in post edit screen
 */
function mkto_save_post_add_meta_box() {
    $title = apply_filters( 'mkto_meta_box_title', __( 'Send Email', 'send-email' ) );
    add_meta_box( 'mkto_meta', $title, 'mkto_save_post_meta_box_content' , 'post', 'advanced', 'high' );
}

/**
 * Load meta box content, for sending email (appear on post save)
 */
function mkto_save_post_meta_box_content( $post ) {
    $disabled = get_post_meta( $post->ID, 'send_email_disabled', true );
    $emailsent = get_post_meta( $post->ID, 'email_sent', true );
    $disabled = $disabled || $emailsent;

    if ( $emailsent ) {
        ?>
        <p><strong>An email for this post has already been sent.</strong></p>
        <?php
    }
    ?>
    <p>
        <label for="enable_send_email">
            <input type="checkbox" name="enable_send_email" id="enable_send_email" value="1" <?php checked( empty( $disabled ) ); ?>>
            <?php _e( 'Send email when post has been published.' , 'send-email' ); ?>
        </label>
        <input type="hidden" name="enable_send_email_status_hidden" value="1" />
    </p>

    <?php
}

/**
 * Listener for save_post
 */
function mkto_save_post_meta_box_save( $post_id ) {

    // If this is an autosave, this form has not been submitted, so we don't want to do anything.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return $post_id;
    }

    // If this is just a revision, don't send the email.
    if ( wp_is_post_revision( $post_id ) ) {
        return $post_id;
    }

    if ( isset( $_POST['post_type'] ) ) {
        if ( current_user_can( 'edit_post', $post_id ) ) {
            if ( isset( $_POST['enable_send_email_status_hidden'] ) ) {

                if ( !isset( $_POST['enable_send_email'] ) ) {
                    update_post_meta( $post_id, 'send_email_disabled', 1 );
                } else {
                    delete_post_meta( $post_id, 'send_email_disabled' );
                    delete_post_meta( $post_id, 'email_sent' );
                }
                mkto_scheduleCampaign( $post_id );
            }
        }
    }

    return $post_id;
}

/**
 * Marketo scheduleCampaign() wrapper
 * schedules a next run for a campaign that will send an email with the blog post content
 * for blog post subscribers
 */
function mkto_scheduleCampaign( $post_id ) {

    $debug = true;

    $emailsent = get_post_meta( $post_id->ID, 'email_sent', true );

    //send only one time
    if ( $emailsent != true ) {

        if ( $debug == false ) {
            $disabled = get_post_meta( $post_id, 'send_email_disabled', true );
        }

        if ( ( ( ( get_post_status ( $post_id ) == 'publish' ) || ( get_post_status ( $post_id ) == 'future' )  ) && empty( $disabled ) ) || $debug ) {

            // Get Post Info

            $post = get_post( $post_id );

            $title = $post->post_title;

            $pos = stripos( $post->post_content, '<!--more-->' );
            $pos = ( $pos===false ) ? 50 : $pos;
            $resume = substr( $post->post_content, 0, $pos );
            $link = get_permalink( $post_id ) . '?utm_source=blog&utm_medium=email&utm_campaign=';

            $marketoSoapEndPoint = get_option( 'kwm-mkto-soap-end-point' );
            $marketoUserId = get_option( 'kwm-mkto-marketo-user-id' );
            $marketoSecretKey = get_option( 'kwm-mkto-marketo-secret-key' );
            $marketoNameSpace = get_option( 'kwm-mkto-marketo-name-space' );

            $marketoProgramName = get_option( 'kwm-mkto-marketo-program-name' );
            $marketoCampaignName = get_option( 'kwm-mkto-marketo-campaign-name' );

            $marketoTitleToken = get_option( 'kwm-mkto-marketo-token-title' );
            $marketoContentToken = get_option( 'kwm-mkto-marketo-token-content' );
            $marketoLinkToken = get_option( 'kwm-mkto-marketo-token-link' );

            // Create Signature
            $dtzObj = new DateTimeZone( "America/Los_Angeles" );
            $dtObj  = new DateTime( 'now', $dtzObj );
            $timeStamp = $dtObj->format( DATE_W3C );
            $encryptString = $timeStamp . $marketoUserId;
            $signature = hash_hmac( 'sha1', $encryptString, $marketoSecretKey );

            // Create SOAP Header
            $attrs = new stdClass();
            $attrs->mktowsUserId = $marketoUserId;
            $attrs->requestSignature = $signature;
            $attrs->requestTimestamp = $timeStamp;

            $authHdr = new SoapHeader( $marketoNameSpace, 'AuthenticationHeader', $attrs );
            $options = array( "connection_timeout" => 20, "location" => $marketoSoapEndPoint );

            if ( $debug ) {
                $options["trace"] = true;
            }

            // Create Request
            $params = new stdClass();
            $params->programName = $marketoProgramName;
            $params->campaignName = $marketoCampaignName;


            if ( get_post_status ( $post_id ) == 'future' ) {
                $dtObj = new DateTime( $post->post_date, new DateTimeZone( 'America/Los_Angeles' ) );
                $params->campaignRunAt = $dtObj->format( DATE_W3C );

            } else {
                $dtObj = new DateTime( 'now', $dtzObj );
                $params->campaignRunAt = $dtObj->format( DATE_W3C );
            }


            $token = new stdClass();
            $token->name = $marketoTitleToken;
            $token->value = $title;

            $token_body = new stdClass();
            $token_body->name = $marketoContentToken;
            $token_body->value = $resume;

            $token_link = new stdClass();
            $token_link->name = $marketoLinkToken;
            $token_link->value = $link;

            $params->programTokenList->attrib = array( $token, $token_body, $token_link );

            $params = array( "paramsScheduleCampaign" => $params );

            $soapClient = new SoapClient( $marketoSoapEndPoint ."?WSDL", $options );
            try {
                $response = $soapClient->__soapCall( 'scheduleCampaign', $params, $options, $authHdr );
            }
            catch( Exception $ex ) {
                var_dump( $ex );
                return $post_id;
            }

            if ( $debug ) {
                echo "RAW request:\n" .$soapClient->__getLastRequest() ."\n";
                echo "RAW response:\n" .$soapClient->__getLastResponse() ."\n";
            }

            update_post_meta( $post_id, 'send_email_disabled', 1 );
            update_post_meta( $post_id, 'email_sent', 1 );

        }
        return $post_id;
    }
    return $post_id;
}


/**
 * Marketo syncLead() wrapper, adapted to register an event
 * This function will insert or update a single lead record.  When updating an existing lead, the lead can be identified with one of the following keys:
 *
 * Marketo ID
 * Foreign system ID
 * Marketo Cookie (created by Munchkin JS script)
 * Email
 *
 * more info: http://developers.marketo.com/documentation/soap/synclead/
 */
function kwm_syncLeadEvent( $eventValue ) {

    $debug = false;

    $marketoSoapEndPoint = get_option( 'kwm-mkto-soap-end-point' );
    $marketoUserId = get_option( 'kwm-mkto-marketo-user-id' );
    $marketoSecretKey = get_option( 'kwm-mkto-marketo-secret-key' );
    $marketoNameSpace = get_option( 'kwm-mkto-marketo-name-space' );

    // Create Signature
    $dtzObj = new DateTimeZone( "America/Los_Angeles" );
    $dtObj  = new DateTime( 'now', $dtzObj );
    $timeStamp = $dtObj->format( DATE_W3C );
    $encryptString = $timeStamp . $marketoUserId;
    $signature = hash_hmac( 'sha1', $encryptString, $marketoSecretKey );

    // Create SOAP Header
    $attrs = new stdClass();
    $attrs->mktowsUserId = $marketoUserId;
    $attrs->requestSignature = $signature;
    $attrs->requestTimestamp = $timeStamp;
    $authHdr = new SoapHeader( $marketoNameSpace, 'AuthenticationHeader', $attrs );
    $options = array( "connection_timeout" => 20, "location" => $marketoSoapEndPoint );
    if ( $debug ) {
        $options["trace"] = true;
    }

    // Lead attributes to update
    $attr1 = new stdClass();
    $attr1->attrName  = "Event";
    $attr1->attrValue = $eventValue;

    $attrArray = array( $attr1 );
    $attrList = new stdClass();
    $attrList->attribute = $attrArray;
    $leadKey->leadAttributeList = $attrList;

    $leadRecord = new stdClass();
    $leadRecord->leadRecord = $leadKey;
    $leadRecord->marketoCookie = $_COOKIE['_mkto_trk'];

    $leadRecord->returnLead = false;

    $params = array( "paramsSyncLead" => $leadRecord );

    $soapClient = new SoapClient( $marketoSoapEndPoint ."?WSDL", $options );
    try {
        $result = $soapClient->__soapCall( 'syncLead', $params, $options, $authHdr );
    }
    catch( Exception $ex ) {
        var_dump( $ex );
    }

    if ( $debug ) {
        echo "RAW request:\n" .$soapClient->__getLastRequest() ."\n";
        echo "RAW response:\n" .$soapClient->__getLastResponse() ."\n";
    }

}

function kwm_call_code() {

    $track = get_option( 'kwm-mkto-tracking' );
    if ( $track ) {
        ?>
        <script type="text/javascript">
        document.write(unescape("%3Cscript src='//munchkin.marketo.net/munchkin.js' type='text/javascript'%3E%3C/script%3E"));
        </script>
        <script type="text/javascript">
        Munchkin.init("<?php echo get_option( 'kwm-mkto-account-id' ); ?>");
        </script>
        <?php
    }

}

function kwm_admin_menu() {
    add_options_page( 'k Wordpress Mkto', 'kWordpress-Mkto', 'administrator', 'k-wordpress-mkto.php', 'kwm_options_page' );
}

function kwm_register_settings() {
    register_setting( 'kwm-settings-group-api', 'kwm-mkto-account-id' );
    register_setting( 'kwm-settings-group-api', 'kwm-mkto-api-key' );
    register_setting( 'kwm-settings-group-api', 'kwm-mkto-tracking' );

    register_setting( 'kwm-settings-group-api', 'kwm-mkto-soap-end-point' );
    register_setting( 'kwm-settings-group-api', 'kwm-mkto-marketo-user-id' );
    register_setting( 'kwm-settings-group-api', 'kwm-mkto-marketo-secret-key' );
    register_setting( 'kwm-settings-group-api', 'kwm-mkto-marketo-name-space' );

    register_setting( 'kwm-settings-group-api', 'kwm-mkto-marketo-program-name' );
    register_setting( 'kwm-settings-group-api', 'kwm-mkto-marketo-campaign-name' );

    register_setting( 'kwm-settings-group-api', 'kwm-mkto-marketo-token-title' );
    register_setting( 'kwm-settings-group-api', 'kwm-mkto-marketo-token-content' );
    register_setting( 'kwm-settings-group-api', 'kwm-mkto-marketo-token-link' );

}

function kwm_options_page() {
    ?>
    <div class="wrap">
        <h2>kWordpress-Mkto</h2>

        <form method="post" action="options.php">
            <?php settings_fields( 'kwm-settings-group-api' ); ?>
            <?php do_settings_sections( 'kwm-settings-group-api' ); ?>
            <style>
            #kwm-field-mapping tr th, #kwm-field-mapping tr td {
                padding: 3px 0;
            }
            </style>
            <table class="form-table">
                <thead>
                    <tr valign="top">
                        <th scope="row">Marketo Account ID:</th>
                        <td><input style="width:500px" type="text" name="kwm-mkto-account-id" value="<?php echo get_option( 'kwm-mkto-account-id' ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Munchkin API Key:</th>
                        <td><input style="width:500px" type="text" name="kwm-mkto-api-key" value="<?php echo get_option( 'kwm-mkto-api-key' ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Track Lead Activity:</th>
                        <?php
                        $track = get_option( 'kwm-mkto-tracking' );
                        if ( $track ) $track = 'checked';
                        ?>
                        <td><input type="checkbox" name="kwm-mkto-tracking" <?php echo $track; ?> /></td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Soap End Point:</th>
                        <td><input style="width:500px" type="text" name="kwm-mkto-soap-end-point" value="<?php echo get_option( 'kwm-mkto-soap-end-point' ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">User Id:</th>
                        <td><input style="width:500px" type="text" name="kwm-mkto-marketo-user-id" value="<?php echo get_option( 'kwm-mkto-marketo-user-id' ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Secret Key:</th>
                        <td><input style="width:500px" type="text" name="kwm-mkto-marketo-secret-key" value="<?php echo get_option( 'kwm-mkto-marketo-secret-key' ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Name Space:</th>
                        <td><input style="width:500px" type="text" name="kwm-mkto-marketo-name-space" value="<?php echo get_option( 'kwm-mkto-marketo-name-space' ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Program Name:</th>
                        <td><input style="width:500px" type="text" name="kwm-mkto-marketo-program-name" value="<?php echo get_option( 'kwm-mkto-marketo-program-name' ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Campaign Name:</th>
                        <td><input style="width:500px" type="text" name="kwm-mkto-marketo-campaign-name" value="<?php echo get_option( 'kwm-mkto-marketo-campaign-name' ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Email Token Title:</th>
                        <td><input style="width:500px" type="text" name="kwm-mkto-marketo-token-title" value="<?php echo get_option( 'kwm-mkto-marketo-token-title' ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Email Token Content:</th>
                        <td><input style="width:500px" type="text" name="kwm-mkto-marketo-token-content" value="<?php echo get_option( 'kwm-mkto-marketo-token-content' ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Email Token Link:</th>
                        <td><input style="width:500px" type="text" name="kwm-mkto-marketo-token-link" value="<?php echo get_option( 'kwm-mkto-marketo-token-link' ); ?>" /></td>
                    </tr>
                </thead>
            </table>

            <?php submit_button(); ?>

        </form>
    </div>

    <?php
}

?>
