<?php

class JSONAPI_Doc {

    protected $id;

    protected $type;

    protected $_pod;

    protected $_post;

    protected $_attributes = [];

    protected $_relationships = [];
    
    public function __construct( $id, $type ) {

        $this->id = $id;
        $this->type = $type;

        $pod = pods($type, $id);

        foreach ( $pod->fields() as $pods_field_name => $pods_field_data ) {

            if ( PodsRESTFields::field_allowed_to_extend( $pods_field_name, $pod, 'read' ) ) {
                $output_type = pods_v('rest_pick_response', $pods_field_data['options'], 'array');
                $isRelationship = 'pick' == pods_v('type', $pods_field_data) && 'id' == $output_type;

                if ($isRelationship) {
                    $this->_relationships[] = $pods_field_name;
                } else {
                    $this->_attributes[] = $pods_field_name;
                }
            }

        }

        $object_fields = [];
        if ( isset( $pod->pod_data['object_fields'] ) && ! empty( $pod->pod_data['object_fields'] ) ) {
            $object_fields = array_keys( $pod->pod_data['object_fields'] );
        }

        $post =  $pod->api->export_pod_item(array(
            'depth' => 1,
            'fields' => array_merge($this->_attributes, $this->_relationships, $object_fields)
        ), $pod);

        if ( self::remove( 'post_author', $object_fields ) ) {
            $post['author'] = $post['post_author'];
            $this->_relationships[] = 'author';
        }

        if ( self::remove( 'featured_media', $object_fields ) ) {
            $post['featured'] = $post['featured_media'];
            $this->_relationships[] = 'featured';
        }

        if ( self::remove( 'post_parent', $object_fields ) ) {
            $post['parent'] = $post['post_parent'];
            $this->_relationships[] = 'parent';
        }

        $this->_attributes = array_merge( $this->_attributes, $object_fields );

        $this->_post = $post;
        $this->_pod = $pod;

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
    public function getAttribute($attribute ) {

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
            $ids = $this->_post[$relationship]; // relationship data
            $field_data = $this->_pod->fields($relationship);

            if ( 'post_type' != pods_v( 'pick_object', $field_data ) ) {
                // Non post_type relationships are not implemented
                continue;
            }

            $type = pods_v( 'pick_val', $field_data );
            $data = [];

            if ( 'array' == gettype( $ids ) ) {
                foreach ( $ids as $id ) {
                    array_push( $data, $this->serialize_relationship( $id, $type ) );
                }
                $relationships[ $relationship ] = array( 'data' => $data );
                continue;
            }

            if ( $ids ) {
                $relationships[ $relationship ] = array( 'data' => $this->serialize_relationship( $ids, $type ) );
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
    protected static function remove( $needle, $haystack ) {
        $index = array_search( $needle, $haystack );
        if ( is_null( $index ) ) {
            return false;
        }
        unset($haystack[$needle]);
        return true;
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