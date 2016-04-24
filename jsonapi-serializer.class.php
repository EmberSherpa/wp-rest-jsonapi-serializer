<?php

class JSONAPI_Serializer {

    public static function rest_post_dispatch($result, $server, $request) {
        $method = $request->get_method();

        /**
         * Currently only supporting READ operations.
         */
        if ('GET' == $method) {

            $data = [];
            $included = [];
            $meta = null;

            if ( self::isQuery($result) ) {

                foreach ( $result->data as $post ) {
                    $doc = new JSONAPI_Doc($post['id'], $post['type']);

                    $data[] = $doc->doc();

                    $included = array_merge($included, $doc->includes());
                }

                $meta = array(
                    'total' => $result->headers['X-WP-Total'],
                    'total-pages' => $result->headers['X-WP-TotalPages']
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

    public static function isQuery( $result ) {
        return isset($result->headers['X-WP-Total']) && isset($result->headers['X-WP-TotalPages']);
    }

}