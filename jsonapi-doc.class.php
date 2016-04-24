<?php

class JSONAPI_Doc {

    protected $id;

    protected $type;

    protected $_pod;

    protected $_post;

    protected $_attributes = [];

    protected $_relationships = [];

    protected $wp_relationships = array(
        'post_author' => array(
            'key' => 'author',
            'type' => 'user'
        ),
        'featured_media' => array(
            'key' => 'featured',
            'type' => 'media'
        ),
        'post_parent' => array(
            'key' => 'parent',
            'type' => '__current__'
        ),
        'post_tag' => array(
            'key' => 'tags',
            'type' => 'tag'
        ),
        'category' => array(
            'key' => 'categories',
            'type' => 'category'
        )
    );

    protected $unnecessary_fields = [
        'ID',
        'post_status',
        'comment_status',
        'post_password',
        'to_ping',
        'pinged',
        'menu_order',
        'ping_status',
        'post_content_filtered',
        'post_type'
    ];

    protected $relationship_fields = [
        'post_author', 'featured_media', 'post_parent', 'post_tag', 'category'
    ];

    public function __construct( $id, $type ) {

        $this->id = $id;
        $this->type = $type;

        if ( 'media' === $type ) {
            $post = $this->setup_media( $id );
        } else {
            $post = $post = $this->setup_pod( $id, $type );
        }

        $this->_post = $post;

    }

    private function setup_media( $id ) {

        $post = get_post( $id );

        return $post;
    }

    private function setup_pod( $id, $type ) {
        $pod = pods( $type, $id );

        if ( !$pod ) {
            return false;
        }

        $relationships = [];
        $attributes = [];

        foreach ( $pod->fields() as $pods_field_name => $pods_field_data ) {

            if ( PodsRESTFields::field_allowed_to_extend( $pods_field_name, $pod, 'read' ) ) {
                $output_type = pods_v('rest_pick_response', $pods_field_data['options'], 'array');
                $isRelationship = 'pick' == pods_v('type', $pods_field_data) && 'id' == $output_type;

                if ($isRelationship) {
                    $relationships[] = $pods_field_name;
                } else {
                    $attributes[] = $pods_field_name;
                }
            }

        }

        $object_fields = [];
        if ( isset( $pod->pod_data['object_fields'] ) && ! empty( $pod->pod_data['object_fields'] ) ) {
            $object_fields = array_keys( $pod->pod_data['object_fields'] );
        }

        $post =  $pod->api->export_pod_item(array(
            'depth' => 1,
            'fields' => array_merge($attributes, $relationships, $object_fields)
        ), $pod);

        foreach ( $this->unnecessary_fields as $to_be_removed ) {
            self::remove( $to_be_removed, $object_fields );
        }

        foreach ( $this->relationship_fields as $relationship_key ) {
            if ( self::remove( $relationship_key, $object_fields ) ) {
                $relationships[] = $relationship_key;
            }
        }

        $this->_pod = $pod;
        $this->_attributes = array_merge( $attributes, $object_fields );
        $this->_relationships = $relationships;

        return $post;
    }

    public function serialize() {
        return array(
            'data'      => $this->doc(),
            'included'  => $this->includes()
        );
    }

    public function doc() {
        $doc = array(
            'id' => $this->id,
            'type' => $this->type
        );

        $attributes = $this->attributes();
        if ( !empty( $attributes ) ) {
            $doc['attributes'] = $attributes;
        }

        $relationships = $this->relationships();
        if ( !empty( $relationships ) ) {
            $doc['relationships'] = $relationships;
        }

        return $doc;
    }

    public function attributes() {
        $attributes = [];
        foreach ( $this->_attributes as $attribute ) {
            if ( 'id' != $attribute || 'ID' != $attribute ) {
                $attributes = array_merge( $attributes, $this->getAttribute( $attribute ) );
            }
        }
        return $attributes;
    }

    /**
     * Returns value for an attribute in form of array. Array will have one key and value.
     * Key will represent the attribute name that should be used for that attribute.
     *
     * @param $attribute
     * @return array
     */
    public function getAttribute( $attribute ) {

        $id = $this->id;
        $post = $this->_post;

        switch ( $attribute ) {
            case 'post_date':
                return array('date' => self::prepare_date_response($post['post_date_gmt'], $post['post_date']));
            case 'post_date_gmt':
                return array('date_gmt' => self::prepare_date_response($post['post_date_gmt']));
            case 'post_modified':
                return array('modified' => self::prepare_date_response($post['post_modified_gmt'], $post['post_modified']));
            case 'post_modified_gmt':
                return array('date_gmt' => self::prepare_date_response($post['post_modified_gmt']));
            case 'guid':
                return array('guid' => apply_filters('get_the_guid', $post['guid']));
            case 'post_name':
                return array('slug' => $post['post_name']);
            case 'post_title':
                return array('title' => $post['post_title']);
            case 'post_content':
                return array('content' => apply_filters('the_content', $post['post_content']));
            case 'post_excerpt':
                return array('excerpt' => apply_filters('the_excerpt', apply_filters('get_the_excerpt', $post['post_excerpt'])));
            case 'sticky':
                return array('sticky' => is_sticky($id));
            case 'format':
                $format = get_post_format($id);
                return array('format' => empty($format) ? 'standard' : $format);
        }

        /** @noinspection PhpUnreachableStatementInspection */
        return array( $attribute => $post[ $attribute ] );

    }

    public function relationships() {
        $relationships = [];

        foreach ( $this->_relationships as $relationship ) {
            $ids = $this->_post[ $relationship ]; // relationship data
            $field_data = $this->_pod->fields($relationship);

            if ( isset( $this->wp_relationships[ $relationship ] ) ) {
                $key = $this->wp_relationships[ $relationship ]['key'];
                if ( $field_data ) {
                    $type = pods_v( 'pick_object', $field_data );
                    if ( 'post_type' === $type ) {
                        $pick_val = pods_v( 'pick_val', $field_data );
                        if ( '__current__' === $pick_val ) {
                            $type = pods_v( 'pod', $this->_pod );
                        }
                    }
                } else {
                    $type = $this->wp_relationships[ $relationship ]['type'];
                }
            } else {
                $key = $relationship;
                $type = pods_v( 'pick_val', $field_data );
            }

            $data = [];

            if ( 'array' == gettype( $ids ) ) {
                foreach ( $ids as $id ) {
                    array_push( $data, $this->serialize_relationship( $id, $type ) );
                }
                $relationships[ $key ] = array( 'data' => $data );
                continue;
            }

            if ( $ids ) {
                $relationships[ $key ] = array( 'data' => $this->serialize_relationship( $ids, $type ) );
            }
        }

        return $relationships;
    }

    /**
     * Adds doc to included
     *
     * @param $id
     * @param $type
     * @return array
     */
    public function serialize_relationship( $id, $type ) {
        if ( 'taxonomy' === $type && self::is_hash( $id ) ) {
            $id = pods_v( 'term_id', $id );
        }
        return array(
            'id' => $id,
            'type' => $type
        );
    }

    public function includes() {

        $includes = [];

        $relationships = $this->relationships();

        foreach ( $relationships as $relationship ) {

            $data = $relationship['data'];
            if ( self::is_hash( $data ) ) {
                $includes[] = $this->serialize_include( $data['id'], $data['type'] );
                continue;
            }

            foreach ( $data as $item ) {
                $includes[] = $this->serialize_include( $item['id'], $item['type'] );
            }

        }

        return $includes;

    }

    public function serialize_include( $id, $type ) {
        $doc = new JSONAPI_Doc( $id, $type );
        return $doc->doc();
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

    /**
     * Remove given item from array by value. If removed, return true, otherwise return false
     *
     * @param $needle
     * @param $haystack
     * @return bool
     */
    protected static function remove( $needle, & $haystack ) {
        $index = array_search( $needle, $haystack );
        if ( is_null( $index ) && false === $index ) {
            return false;
        }
        return !empty( array_splice( $haystack, $index, 1 ) );
    }

    /**
     * Check if array is associative (ie has string keys)
     * @source http://stackoverflow.com/a/173479/172894
     * @param $array
     * @return bool
     */
    protected static function is_hash($array) {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Check if array is an index array (ie has integer keys)
     * @param $array
     * @return bool
     */
    protected static function is_array($array) {
        return !self::is_hash($array);
    }
}