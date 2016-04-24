<?php
/*
Plugin Name: WP-REST JSONAPI Serializer
Plugin URI: http://github.com/EmberSherpa/wp-rest-jsonapi-serializer
Description: Converts WP REST API Response to JSONAPI spec
Version: 1.0
Author: Taras Mankovski
Author URI: http://www.embersherpa.com
License: GPL2
*/

require_once(__DIR__ . '/jsonapi-serializer.class.php');

class WP_REST_JSOINAPI_Serializer {

    static function initialize() {
        if ( !function_exists( 'pods_transient_get' ) ) {
            return;
        }

        add_filter('rest_post_dispatch', 'JSONAPI_Serializer::rest_post_dispatch', 10, 4);

        $rest_bases = pods_transient_get( 'pods_rest_bases' );
        if ( !empty( $rest_bases ) ) {

            foreach ( $rest_bases as $pod_name => $pod ) {

                if ( 'post_type' == $pod['type'] ) {
                    add_filter( "rest_prepare_{$pod_name}", 'JSONAPI_Serializer::rest_prepare_pod', 10, 3);
                }

            }
        }

    }

}

add_action('init', 'WP_REST_JSOINAPI_Serializer::initialize');