<?php

/**
 * Base class for all backends.
 * Child classes must implement the methods below.
 */
abstract class LuceneBackend extends Object {

    protected static $extractor_classes = array();

    protected $frontend;

    //////////     Runtime operations

    public function __construct(&$frontend) {
        $this->frontend =& $frontend;
    }

    /**
     * Indexes any DataObject which has the LuceneSearchable extension.
     * @param $item (DataObject) The object to index.
     */
    abstract public function index($item);
    
    /**
     * Queries the search engine and returns results.
     * @param $query_string (String) The query to send to the search engine.
     * @return (DataObjectSet) A DataObjectSet of DataObject search results.
     */
    abstract public function find($query_string);

    /**
     * Deletes a DataObject from the search index.
     * @param $item (DataObject) The item to delete.
     */
    abstract public function delete($item);

    //////////     Non-backend-specific helper functions

    protected function extract_text($item) {
        if ( ! $item->is_a('File') ) return '';
        if ( $item->class == 'Folder' ) return '';
        foreach( self::get_text_extractor_classes() as $extractor_class ) {
            $extensions = new ReflectionClass($extractor_class);
            $extensions = $extensions->getStaticPropertyValue('extensions');
            if ( in_array(strtolower(File::get_file_extension($item->Filename)), $extensions) ) {
                continue;
            }
            // Try any that support the given file extension
            $content = call_user_func(
                array($extractor_class, 'extract'), 
                Director::baseFolder().'/'.$item->Filename
            );
            if ( ! $content ) continue;
            return $content;
        }
        return '';
    }

    /**
     * Returns the list of available subclasses of ZendSearchLuceneTextExtractor
     * in the order in which they should be processed.  Order is determined by
     * the $priority static on each class.  Default is 100 for all inbuilt 
     * classes, lower numbers get run first.
     *
     * @access private
     * @static
     * @return  Array   An array of strings containing classnames.
     */
    protected static function get_text_extractor_classes() {
        if ( ! self::$extractor_classes ) {
            $all_classes = ClassInfo::subclassesFor('LuceneTextExtractor');
            usort(
                $all_classes,
                create_function('$a, $b', '
                    $pa = new ReflectionClass($a);
                    $pa = $pa->getStaticPropertyValue(\'priority\');
                    $pb = new ReflectionClass($b);
                    $pb = $pb->getStaticPropertyValue(\'priority\');
                    if ( $pa == $pb ) return 0;
                    return ($pa < $pb) ? -1 : 1;'
                )
            );
            self::$extractor_classes = $all_classes;
        }
        return self::$extractor_classes;
    }

    /**
     * Function to reduce a nested dot-notated field name to a string value.
     * Recurses into itself, going as deep as the relation needs to until it
     * ends up with a string to return.
     *
     * If the fieldname can't be resolved for the given object, returns an empty
     * string rather than failing.
     */
    public static function getFieldValue($object, $fieldName) {
        if ( strpos($fieldName, '.') === false ) {
            if ( $object->hasMethod($fieldName) ) {
                // Method on object
                return $object->$fieldName();
            } else {
                // Bog standard field
                return $object->$fieldName;
            }
        }
        // Using dot notation
        list($baseFieldName, $relationFieldName) = explode('.', $fieldName, 2);
        // has_one
        if ( in_array($baseFieldName, array_keys($object->has_one())) ) {
            $field = $object->getComponent($baseFieldName);
            return $this->getFieldValue($field, $relationFieldName);
        }
        // has_many
        if ( in_array($baseFieldName, array_keys($object->has_many())) ) {
            // loop through and get string values for all components
            $tmp = '';
            $components = $object->getComponents($baseFieldName);
            foreach( $components as $component ) {
                $tmp .= $this->getFieldValue($component, $relationFieldName)."\n";
            }
            return $tmp;
        }
        // many_many
        if ( in_array($baseFieldName, array_keys($object->many_many())) ) {
            // loop through and get string values for all components
            $tmp = '';
            $components = $object->getManyManyComponents($baseFieldName);
            foreach( $components as $component ) {
                $tmp .= $this->getFieldValue($component, $relationFieldName)."\n";
            }
            return $tmp;
        }
        // Nope, not able to be indexed :-(
        return '';
    }
    
    // Configuration

    

}

