<?php

class ZendLuceneBackend extends LuceneBackend {

    protected $index = null;

    protected $config;

    public static $default_config = array(
    );

    //////////     Zend-specific Configuration
    
    public function __construct(&$frontend, $config = null) {
        parent::__construct($frontend);

        if ( ! is_array($config) ) $config = array();
        $this->config = array_merge(self::$default_config, $config);

        Zend_Search_Lucene_Search_QueryParser::setDefaultEncoding(
            $this->frontend->getConfig('encoding')
        );
        Zend_Search_Lucene_Analysis_Analyzer::setDefault( 
            new StandardAnalyzer_Analyzer_Standard_English() 
        );
    }

    public function setConfig($key, $value) {
        $this->config[$key] = $value;
    }

    public function getConfig($key) {
        if ( ! isset($this->config[$key]) ) return null;
        return $this->config[$key];    
    }

    //////////     Runtime methods

    /**
     * Indexes any DataObject which has the LuceneSearchable extension.
     * @param $item (DataObject) The object to index.
     */
    public function index($item) {
        if ( ! Object::has_extension($item->ClassName, 'LuceneSearchable') ) {
            return;
        }

        // Remove currently indexed data for this object
        $this->delete($item);

        $doc = new Zend_Search_Lucene_Document();

        // Add in text extracted from files, if any
        $extracted_text = $this->extract_text($item);
        if ( $extracted_text ) {
            $field = Zend_Search_Lucene_Field::UnStored(
                'body',  // We're storing extracted text from files in a field called 'body'.
                $extracted_text, 
                $this->frontend->getConfig('encoding')
            );
            $doc->addField($field);
        }
       
        // Index the fields we've specified in the config
        $fields = array_merge($item->getExtraSearchFields(), $item->getSearchFields());
        foreach( $fields as $fieldName ) {
            $field_config = $item->getLuceneFieldConfig($fieldName);
            // Normal database field or function call
            $field = $this->getZendField($item, $fieldName);
            if ( isset($field_config['boost']) ) $field->boost = $field_config['boost'];
            $doc->addField($field);            
        }

        // Add URL if we have a function called Link().  We didn't use the 
        // extraSearchFields mechanism for this because it's not a property on 
        // all objects, so this is the most sensible place for it.
        if ( method_exists(get_class($item), 'Link') && ! in_array('Link', $fields) ) {
            $doc->addField(Zend_Search_Lucene_Field::UnIndexed('Link', $item->Link()));
        }

        $this->getIndex()->addDocument($doc);
    }

    public function doIndex() {
        return $this->index();
    }
    
    /**
     * Queries the search engine and returns results.
     * All dataobjects have two additional properties added:
     *   - LuceneRecordID: the 'hit ID' which can be used to delete the record
     *     from the database.
     *   - LuceneScore: the 'score' assigned to the result by Lucene.
     * @param $query_string (String) The query to send to the search engine.
     * @return (DataObjectSet) A DataObjectSet of DataObject search results.
     */
    public function find($query_string) {
        $out = Object::create('DataObjectSet');
        try {
            $hits = $this->getIndex()->find($query_string);
            foreach( $hits as $hit ) {
                $obj = DataObject::get_by_id($hit->ClassName, $hit->ObjectID);
                if ( ! $obj ) continue;
                $obj->LuceneRecordID = $obj->id;
                $obj->LuceneScore = $obj->score;
                $out->push($obj);
            }
        } catch ( Exception $e) { 
            user_error(
                'Zend_Search_Lucene threw an exception: '.(string)$e,
                E_USER_WARNING
            );
        }
        return $out;
    }

    /**
     * Deletes a DataObject from the search index.
     * @param $item (DataObject) The item to delete.
     */
    public function delete($item) {
        $index =& $this->getIndex();
        foreach ($index->find('ObjectID:'.$item->ID) as $hit) {
            if ( $hit->ClassName != $item->ClassName ) continue;
            $index->delete($hit->id);
        }
    }

    public function doDelete($item) {
        return $this->delete($item);
    }

    public function wipeIndex() {
        $this->getIndex(true);
    }

    public function commit() {
        $this->getIndex()->commit();
    }
    
    public function optimize() {
        $this->getIndex()->optimize();    
    }

    public function close() {
        // not needed, Zend tidies this up by itself
    }

    //////////     Helper methods
    
    public function &getIndex($forceCreate = false) {
        if ( !$forceCreate && $this->index !== null ) {
            return $this->index;
        }
        $indexFilename = $this->frontend->getIndexDirectoryName();
        if ( !$forceCreate && file_exists($indexFilename) ) {
            $this->index =& Zend_Search_Lucene::open($indexFilename);
        } else {
            $this->index =& Zend_Search_Lucene::create($indexFilename);
        }
        return $this->index;
    }

    /**
     * Builder method for returning a Zend_Search_Lucene_Field object based on 
     * the DataObject field.
     *
     * If the SilverStripe database field is a Date or a descendant of Date, 
     * stores the date as a Unix timestamp.  Make sure your timezone is set 
     * correctly!
     *
     * Keyword - Data that is searchable and stored in the index, but not 
     *      broken up into tokens for indexing. This is useful for being 
     *      able to search on non-textual data such as IDs or URLs.
     *
     * UnIndexed – Data that isn’t available for searching, but is stored 
     *      with our document (eg. article teaser, article URL  and timestamp 
     *      of creation)
     *
     * UnStored – Data that is available for search, but isn’t stored in 
     *      the index in full (eg. the document content)
     *
     * Text – Data that is available for search and is stored in full 
     *      (eg. title and author)
     *
     * @access private
     * @param   DataObject  $object     The DataObject from which to extract a
     *                                  Zend field.
     * @param   String      $fieldName  The name of the field to fetch a Zend field for.
     * @return  Zend_Search_Lucene_Field
     */
    protected function getZendField($object, $fieldName) {
        $encoding = $this->frontend->getConfig('encoding');
        $config = $object->getLuceneFieldConfig($fieldName);

        // Recurses through dot-notation.
        $value = self::getFieldValue($object, $fieldName);

        if ( $config['content_filter'] ) {
            // Run through the content filter, if we have one.
            $value = call_user_func($config['content_filter'], $value);
        }

        if ( ! $value ) $value = '';

        if ( $config['name'] ) {
            $fieldName = $config['name'];
        }

        if ( $config['type'] == 'text' ) {
            return Zend_Search_Lucene_Field::Text($fieldName, $value, $encoding);
        }
        if ( $config['type'] == 'unindexed' ) {
            return Zend_Search_Lucene_Field::UnIndexed($fieldName, $value, $encoding);
        }
        if ( $config['type'] == 'keyword' ) {
            $keywordFieldName = $fieldName;
            if ( $keywordFieldName == 'ID' ) $keywordFieldName = 'ObjectID'; // Don't use 'ID' as it's used by Zend Lucene
            return Zend_Search_Lucene_Field::Keyword($keywordFieldName, $value, $encoding);
        }
        // Default - index and store as unstored
        return Zend_Search_Lucene_Field::UnStored($fieldName, $value, $encoding);
    }

    /**
     * Cleans up the index if it's needed.
     */
    public function __destruct() {
        if ( $this->index === null ) return;
        $this->getIndex()->commit();
    }

}


