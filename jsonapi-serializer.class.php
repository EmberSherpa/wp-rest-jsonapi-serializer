<?php
class JSONAPI_Serializer {

    public static function rest_post_dispatch($result, $server, $request) {
        $method = $request->get_method();

        /**
         * Currently only supporting READ operations.
         */
        if ('GET' == $method) {

            $isQuery = isset($result->headers['X-WP-Total']) && isset($result->headers['X-WP-TotalPages']);
            if (isQuery) {

                
                $data = array(
                    'data' => [],
                    'meta' => array(
                        'total' => $result->headers['X-WP-Total'],
                        'total-pages' => $result->headers['X-WP-TotalPages']
                    ),
                    'included' => []
                );
                $result->set_data($data);
            }

        }

        return $result;
    }

    public static function rest_prepare_pod( $response, $post, $request ) {
        
        if ( 'GET' != $request->get_method() ) {
            return $response;
        }

        $data = $response->get_data();

        if ( isset( $data['_included'] ) ) {
            $included = $data['_included'];
        } else {
            $included = $data['_included'] = [];
        }

        $pod = pods($post->post_type, $post->ID);

        if ( $pod ) {
            foreach ( $pod->fields() as $field_name => $field_data ) {
                if ( PodsRESTFields::field_allowed_to_extend( $field_name, $pod, 'read' ) ) {
                    if ('pick' == pods_v('type', $field_data)) {
                        $output_type = pods_v('rest_pick_response', $field_data['options'], 'array');
                        if ('id' == $output_type) {
                            $related_pods = $pod->field( $field_name, array( 'output' => 'pod' ) );
                            foreach ( $related_pods as $related_pod ) {
                                
                                array_push( $included, self::serialize_included($related_pod) );

                                $newValues = self::replace_id_with_data( $data[ $field_name ], pods_v( 'id', $related_pod ), pods_v('pod', $related_pod) );
                                $data[ $field_name ] = $newValues;
                            }
                        }
                    }
                }
            }
            $data['_included'] = $included;
            $response->set_data($data);
        }

        return $response;
    }

    protected static function replace_id_with_data ( $array , $id, $type ) {
        $key = array_search($id, $array);

        if (!is_null($key)) {
            $array[$key] = self::make_doc($id, $type);
        }

        return $array;
    }

    protected static function make_doc($id, $type, $attributes = null, $relationships = null) {
        $data = array(
            'id' => $id,
            'type' => $type
        );

        if (!empty($attributes)) {
            $data['attributes'] = $attributes;
        }

        if ( !empty($relationships)) {
            $data['relationships'] = $relationships;
        }

        return $data;
    }

    /**
     * @param Pods $pod
     * @return array
     */
    protected static function serialize_included( $pod ) {

        $pods_fields = $pod->fields();
        $fields = array_keys( $pods_fields );
        if ( isset( $pod->pod_data['object_fields'] ) && ! empty( $pod->pod_data['object_fields'] ) ) {
            $fields = array_merge( $fields, array_keys( $pod->pod_data['object_fields'] ) );
        }

        $relationships = array();
        $attributes = array();

        $exported =  $pod->api->export_pod_item(array(
            'depth' => 1,
            'fields' => $fields
        ), $pod);

        $id = pods_v( 'id', $pod );
        $type = pods_v( 'pod', $pod );

        foreach ( $pods_fields as $pods_field_name => $pods_field_data ) {
            if ( PodsRESTFields::field_allowed_to_extend( $pods_field_name, $pod, 'read' ) ) {
                $output_type = pods_v('rest_pick_response', $pods_field_data['options'], 'array');

                $pods_field_value = $exported[$pods_field_name];

                $isRelationship = 'pick' == pods_v('type', $pods_field_data) && 'id' == $output_type;
                if ($isRelationship) {
                    if ( 'array' === gettype($pods_field_value)) {
                        // handle array case
                    } else {
                        $relationships[$pods_field_name] = self::make_doc($pods_field_value, pods_v('pod', $pods_field_data));
                    }
                } else {
                    $attributes[$pods_field_name] = $pods_field_value;
                }
            }
        }

        $data = array(
            'date'         => self::prepare_date_response( $exported['post_date_gmt'], $exported['post_date'] ),
            'date_gmt'     => self::prepare_date_response( $exported['post_date_gmt'] ),
            'guid'         => apply_filters( 'get_the_guid', $exported['guid'] ),
            'modified'     => self::prepare_date_response( $exported['post_modified_gmt'], $exported['post_modified'] ),
            'modified_gmt' => self::prepare_date_response( $exported['post_modified_gmt'] ),
            'slug'         => $exported['post_name']
        );

        if ( isset($exported['post_title']) ) {
            $data['title'] = $exported['post_title'];
        }

        if ( isset($exported['post_content'])) {
            $data['content'] = apply_filters( 'the_content', $exported['post_content'] );
        }

        if ( isset($exported['post_excerpt']) ) {
            $data['excerpt'] = apply_filters( 'the_excerpt', apply_filters( 'get_the_excerpt', $exported['post_excerpt'] ) );
        }

        if ( isset($exported['post_author']) ) {
            $relationships['author'] = self::make_doc($exported['post_author'], 'author');
        }

        if ( isset($exported['featured_media']) ) {
            $relationships['featured'] = self::make_doc(get_post_thumbnail_id( $id ), 'media');
        }

        if ( isset($exported['post_parent']) && 0 != $exported['post_parent'] ) {
            $relationships['parent'] = self::make_doc($exported['parent'], $type);
        }

        if ( isset($exported['sticky']) ) {
            $data['sticky'] = is_sticky( $id );
        }
        
        if ( isset($exported['format']) ) {
            $data['format'] = get_post_format( $id );

            if ( empty( $data['format'] ) ) {
                $data['format'] = 'standard';
            }
        }

        foreach ($data as $key => $value) {
            $attributes[$key] = $value;
        }

        return self::make_doc(pods_v('id', $pod), pods_v('pod', $pod), $attributes, $relationships);
    }

    /**
     * Check the post_date_gmt or modified_gmt and prepare any post or
     * modified date for single post output.
     *
     * @param string       $date_gmt
     * @param string|null  $date
     * @return string|null ISO8601/RFC3339 formatted datetime.
     */
    protected static function prepare_date_response( $date_gmt, $date = null ) {
        // Use the date if passed.
        if ( isset( $date ) ) {
            return mysql_to_rfc3339( $date );
        }

        // Return null if $date_gmt is empty/zeros.
        if ( '0000-00-00 00:00:00' === $date_gmt ) {
            return null;
        }

        // Return the formatted datetime.
        return mysql_to_rfc3339( $date_gmt );
    }
}