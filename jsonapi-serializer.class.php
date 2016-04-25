<?php

class JSONAPI_Serializer {

    public static function rest_post_dispatch($result, $server, $request) {
        $method = $request->get_method();

        /**
         * Currently only supporting READ operations.
         */
        if ( 'GET' === $method ) {

            $data = [];
            $included = [];
            $meta = null;

            if ( self::isQuery( $result ) ) {

                foreach ( $result->data as $post ) {
                    $doc = new JSONAPI_Doc($post['id'], $post['type']);

                    $data[] = $doc->doc();

                    $included = array_merge($included, $doc->includes());
                }

                $meta = array(
                    'total' => $result->headers['X-WP-Total'],
                    'totalPages' => $result->headers['X-WP-TotalPages']
                );

            } else {

                if ( !empty( $result->data ) ) {
                    $doc = new JSONAPI_Doc($result->data['id'], $result->data['type']);

                    $data = $doc->doc();
                    $included = $doc->includes();
                }

            }

            $payload = array(
                'data' => $data,
                'included' => $included
            );

            if ( $meta ) {
                $payload['meta'] = $meta;
            }

            $result->set_data( $payload );

        }

        return $result;
    }

    /**
     * Mostly copied from WP_REST_Server::get_json_last_error
     */
    public static function get_json_last_error() {
        // See https://core.trac.wordpress.org/ticket/27799.
        if ( ! function_exists( 'json_last_error' ) ) {
            return false;
        }

        $last_error_code = json_last_error();

        if ( ( defined( 'JSON_ERROR_NONE' ) && JSON_ERROR_NONE === $last_error_code ) || empty( $last_error_code ) ) {
            return false;
        }

        return json_last_error_msg();
    }

    /**
     * Mostly copied from WP_REST_Server::get_json_last_error
     *
     * TODO: adjust this to JSONAPI response
     */
    protected function error_to_response( $error ) {
        $error_data = $error->get_error_data();

        if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
            $status = $error_data['status'];
        } else {
            $status = 500;
        }

        $errors = array();

        foreach ( (array) $error->errors as $code => $messages ) {
            foreach ( (array) $messages as $message ) {
                $errors[] = array( 'code' => $code, 'message' => $message, 'data' => $error->get_error_data( $code ) );
            }
        }

        $data = $errors[0];
        if ( count( $errors ) > 1 ) {
            // Remove the primary error.
            array_shift( $errors );
            $data['additional_errors'] = $errors;
        }

        $response = new WP_REST_Response( $data, $status );

        return $response;
    }

    public static function rest_pre_serve_request( $served, $result, $request, $server ) {

        if ( 'GET' !== $request->get_method() ) {
            return false;
        }

        $result = wp_json_encode( $result->get_data() );

        $json_error_message = self::get_json_last_error();
        if ( $json_error_message ) {
            $json_error_obj = new WP_Error( 'rest_encode_error', $json_error_message, array( 'status' => 500 ) );
            $result = $server->error_to_response( $json_error_obj );
            $result = wp_json_encode( $result->data[0] );
        }

        echo $result;

        return true;
    }

    public static function isQuery( $result ) {
        return isset($result->headers['X-WP-Total']) && isset($result->headers['X-WP-TotalPages']);
    }

}