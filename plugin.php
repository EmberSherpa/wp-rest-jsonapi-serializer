<?php
/*
Plugin Name: WP-REST JSONAPI Serializer
Plugin URI: http://github.com/EmberSherpa/wp-rest-jsonapi-serializer
GitHub Plugin URI: EmberSherpa/wp-rest-jsonapi-serializer
Description: Converts WP REST API Response to JSONAPI spec
Version: 1.1.3
Author: Taras Mankovski
Author URI: http://www.embersherpa.com
License: GPL2
*/

require_once(__DIR__ . '/jsonapi-doc.class.php');
require_once(__DIR__ . '/jsonapi-serializer.class.php');

class WP_REST_JSOINAPI_Serializer {

    static function initialize() {
        add_filter('rest_post_dispatch', 'JSONAPI_Serializer::rest_post_dispatch', 10, 4);
        add_filter('rest_pre_serve_request', 'JSONAPI_Serializer::rest_pre_serve_request', 15, 4);
    }
}

add_action('init', 'WP_REST_JSOINAPI_Serializer::initialize');