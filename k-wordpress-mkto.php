<?php
/**
 * Plugin Name: kWordpress-Mkto
 * Plugin URI: https://github.com/kelter-antunes/kWordpress-Mkto
 * Description: Simple but awesome Marketo integration with Wordpress. It wraps the scheduleCampaign() Api call, to send email to an email subscribers smart list.
 * Version: 0.2
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

    function wmi_scripts() {
        //wp_enqueue_script( 'k-wordpress-mkto.js', plugins_url() . '/os-marketo-integration/k-wordpress-mkto.js', array(), '1.0.0', false );
    }


    function os_scheduleCampaign($post_ID)  {

        $debug = true;

        // Get Post Info

        $post = get_post( $post_ID );

        $title = $post->post_title;

        $pos = stripos( $post->post_content, '<!--more-->' );
        $pos = ($pos===false) ? 50 : $pos;
        $resume = substr( $post->post_content, 0, $pos );
        $link = get_permalink( $post_ID ) . '?utm_source=blog&utm_medium=email&utm_campaign=';


        $marketoSoapEndPoint = get_option('wmi-mkto-soap-end-point');
        $marketoUserId = get_option('wmi-mkto-marketo-user-id');
        $marketoSecretKey = get_option('wmi-mkto-marketo-secret-key');
        $marketoNameSpace = get_option('wmi-mkto-marketo-name-space');

        $marketoProgramName = get_option('wmi-mkto-marketo-program-name');
        $marketoCampaignName = get_option('wmi-mkto-marketo-campaign-name');


        $marketoTitleToken = get_option('wmi-mkto-marketo-token-title');
        $marketoContentToken = get_option('wmi-mkto-marketo-token-content');
        $marketoLinkToken = get_option('wmi-mkto-marketo-token-link');


        // Create Signature
        $dtzObj = new DateTimeZone("America/Los_Angeles");
        $dtObj  = new DateTime('now', $dtzObj);
        $timeStamp = $dtObj->format(DATE_W3C);
        $encryptString = $timeStamp . $marketoUserId;
        $signature = hash_hmac('sha1', $encryptString, $marketoSecretKey);

        // Create SOAP Header
        $attrs = new stdClass();
        $attrs->mktowsUserId = $marketoUserId;
        $attrs->requestSignature = $signature;
        $attrs->requestTimestamp = $timeStamp;

        $authHdr = new SoapHeader($marketoNameSpace, 'AuthenticationHeader', $attrs);
        $options = array("connection_timeout" => 20, "location" => $marketoSoapEndPoint);

        if ($debug) {
            $options["trace"] = true;
        }

        // Create Request
        $params = new stdClass();
        $params->programName = $marketoProgramName;
        $params->campaignName = $marketoCampaignName;
        $dtObj = new DateTime('now', $dtzObj);
        $params->campaignRunAt = $dtObj->format(DATE_W3C);


        $token = new stdClass();
        $token->name = $marketoTitleToken;
        $token->value = $title;

        $token_body = new stdClass();
        $token_body->name = $marketoContentToken;
        $token_body->value = $resume;

        $token_link = new stdClass();
        $token_link->name = $marketoLinkToken;
        $token_link->value = $link;

        $params->programTokenList->attrib = array($token, $token_body, $token_link);


        $params = array("paramsScheduleCampaign" => $params);

        $soapClient = new SoapClient($marketoSoapEndPoint ."?WSDL", $options);
        try {
            $response = $soapClient->__soapCall('scheduleCampaign', $params, $options, $authHdr);
        }
        catch(Exception $ex) {
            var_dump($ex);
        }

        if ($debug) {
            echo "RAW request:\n" .$soapClient->__getLastRequest() ."\n";
            echo "RAW response:\n" .$soapClient->__getLastResponse() ."\n";
        }

        return $post_ID;
    }
    //add_action('publish_post', 'os_scheduleCampaign');


    add_action( 'wp_enqueue_scripts', 'wmi_scripts' );
    add_action( 'admin_enqueue_scripts', 'wmi_scripts' );
    add_action( 'admin_menu', 'wmi_admin_menu');
    add_action( 'admin_init', 'wmi_register_settings' );
    add_action( 'wp_head', 'wmi_call_code');

    function wmi_call_code() { 
        $j = get_option('wmi-mkto-field-map');
        $fields = json_decode($j);
        $output = '';
        foreach ($fields as $map) {
            foreach ($map as $k=>$v) {
                if (strpos($k, '*') !== false) {
                    $emailfield = $v;
                    $k = str_replace('*', '', $k);
                }
                $sendval = $_POST[$v];
                if ($sendval) $output .= $k . ': decodeURIComponent("' . rawurlencode($sendval) . '"),';
            //if ($sendval) $output .= $k . ': "' . $sendval . '",';
            }
        }
        $em = $_POST[$emailfield];
        $h = hash('sha1', get_option('wmi-mkto-api-key') . $em);
        $track = get_option('wmi-mkto-tracking');
        if ($em != '' || $track) {
            ?>
            <script type="text/javascript">
            document.write(unescape("%3Cscript src='//munchkin.marketo.net/munchkin.js' type='text/javascript'%3E%3C/script%3E"));
            </script>
            <script type="text/javascript">
            Munchkin.init("<?php echo get_option('wmi-mkto-account-id'); ?>");
            </script>
            <?php }
            if ($em != '') {
                ?>
                <script type="text/javascript">
                mktoMunchkinFunction("associateLead",{<?php echo rtrim($output, ','); ?>},"<?php echo $h; ?>");
                </script>
                <?php
            }
        }

        function wmi_admin_menu() {
            add_options_page('k Wordpress Mkto', 'kWordpress-Mkto', 'administrator', 'k-wordpress-mkto.php', 'wmi_options_page');
        }

        function wmi_register_settings() {
            register_setting( 'wmi-settings-group-api', 'wmi-mkto-account-id' );
            register_setting( 'wmi-settings-group-api', 'wmi-mkto-api-key' );
            register_setting( 'wmi-settings-group-api', 'wmi-mkto-tracking' );

            register_setting( 'wmi-settings-group-api', 'wmi-mkto-soap-end-point' );
            register_setting( 'wmi-settings-group-api', 'wmi-mkto-marketo-user-id' );
            register_setting( 'wmi-settings-group-api', 'wmi-mkto-marketo-secret-key' );
            register_setting( 'wmi-settings-group-api', 'wmi-mkto-marketo-name-space' );

            register_setting( 'wmi-settings-group-api', 'wmi-mkto-marketo-program-name' );
            register_setting( 'wmi-settings-group-api', 'wmi-mkto-marketo-campaign-name' );

            register_setting( 'wmi-settings-group-api', 'wmi-mkto-marketo-token-title' );
            register_setting( 'wmi-settings-group-api', 'wmi-mkto-marketo-token-content' );
            register_setting( 'wmi-settings-group-api', 'wmi-mkto-marketo-token-link' );
            
        }

        function wmi_options_page() { 

            ?>
            <div class="wrap">
                <h2>kWordpress-Mkto</h2>

                <form method="post" action="options.php">
                    <?php settings_fields( 'wmi-settings-group-api' ); ?>
                    <?php do_settings_sections( 'wmi-settings-group-api' ); ?>
                    <style>
                    #wmi-field-mapping tr th, #wmi-field-mapping tr td {
                        padding: 3px 0;
                    }
                    </style>
                    <table class="form-table">
                        <thead>
                            <tr valign="top">
                                <th scope="row">Marketo Account ID:</th>
                                <td><input style="width:500px" type="text" name="wmi-mkto-account-id" value="<?php echo get_option('wmi-mkto-account-id'); ?>" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Munchkin API Key:</th>
                                <td><input style="width:500px" type="text" name="wmi-mkto-api-key" value="<?php echo get_option('wmi-mkto-api-key'); ?>" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Track Lead Activity:</th>
                                <?php
                                $track = get_option('wmi-mkto-tracking');
                                if ($track) $track = 'checked';
                                ?>
                                <td><input type="checkbox" name="wmi-mkto-tracking" <?php echo $track; ?> /></td>
                            </tr>

                            <tr valign="top">
                                <th scope="row">Soap End Point:</th>
                                <td><input style="width:500px" type="text" name="wmi-mkto-soap-end-point" value="<?php echo get_option('wmi-mkto-soap-end-point'); ?>" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">User Id:</th>
                                <td><input style="width:500px" type="text" name="wmi-mkto-marketo-user-id" value="<?php echo get_option('wmi-mkto-marketo-user-id'); ?>" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Secret Key:</th>
                                <td><input style="width:500px" type="text" name="wmi-mkto-marketo-secret-key" value="<?php echo get_option('wmi-mkto-marketo-secret-key'); ?>" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Name Space:</th>
                                <td><input style="width:500px" type="text" name="wmi-mkto-marketo-name-space" value="<?php echo get_option('wmi-mkto-marketo-name-space'); ?>" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Program Name:</th>
                                <td><input style="width:500px" type="text" name="wmi-mkto-marketo-program-name" value="<?php echo get_option('wmi-mkto-marketo-program-name'); ?>" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Campaign Name:</th>
                                <td><input style="width:500px" type="text" name="wmi-mkto-marketo-campaign-name" value="<?php echo get_option('wmi-mkto-marketo-campaign-name'); ?>" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Email Token Title:</th>
                                <td><input style="width:500px" type="text" name="wmi-mkto-marketo-token-title" value="<?php echo get_option('wmi-mkto-marketo-token-title'); ?>" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Email Token Content:</th>
                                <td><input style="width:500px" type="text" name="wmi-mkto-marketo-token-content" value="<?php echo get_option('wmi-mkto-marketo-token-content'); ?>" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Email Token Link:</th>
                                <td><input style="width:500px" type="text" name="wmi-mkto-marketo-token-link" value="<?php echo get_option('wmi-mkto-marketo-token-link'); ?>" /></td>
                            </tr>
                        </thead>
                    </table>

                    <?php submit_button(); ?>

                </form>
            </div>

            <?php 
        } 

        ?>