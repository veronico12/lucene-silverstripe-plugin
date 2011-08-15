<?php

class JavaLuceneBackend extends LuceneBackend {

    protected $indexWriter = null;

    protected $indexSearcher = null;

    protected $config;

    public static $default_config = array(
        'servlet_port' => 8080
    );

    //////////     Java-specific Configuration
    
    public function __construct(&$frontend, $config = null) {
        parent::__construct($frontend);

        if ( ! is_array($config) ) $config = array();
        $this->config = array_merge(self::$default_config, $config);

        if ( @fopen('http://localhost:'.$this->getConfig('servlet_port').'/JavaBridge/java/Java.inc', 'r') === false ) {
            try {
                // If we failed, try to start the servlet
                $this->startStandalone();
            } catch( JavaException $e ) {
                echo $e;
            }
            // Block while waiting for a connection
            while( @fopen('http://localhost:'.$this->getConfig('servlet_port').'/JavaBridge/java/Java.inc', 'r') === false ) { }            
        }
        require_once( 'http://localhost:'.$this->getConfig('servlet_port').'/JavaBridge/java/Java.inc' );
        if ( ! function_exists('java_truncate') ) {
            user_error('Couldn\'t find JavaBridge/java/Java.inc.');
        }        
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
        $this->doIndex($item);
        $this->commit();
        $this->close();
    }

    public function doIndex($item) {
        // Remove currently indexed data for this object
        $this->delete($item);

        $doc = new java("org.apache.lucene.document.Document");

        // Add in text extracted from files, if any
        $extracted_text = $this->extract_text($item);
        if ( $extracted_text ) {
            $doc->add(
                new java("org.apache.lucene.document.Field",
                    'body', 
                    $extracted_text, 
                    java('org.apache.lucene.document.Field$Store')->YES, 
                    java('org.apache.lucene.document.Field$Index')->NOT_ANALYZED
                )
            );
        }       

        // Index the fields we've specified in the config
        $fields = array_merge($item->getExtraSearchFields(), $item->getSearchFields());
        foreach( $fields as $fieldName ) {
            $field_config = $item->getLuceneFieldConfig($fieldName);
            // Normal database field or function call
            $field = $this->getJavaField($item, $fieldName);
            if ( isset($field_config['boost']) ) $field->setBoost($field_config['boost']);
            $doc->add($field);
        }

        // Add URL if we have a function called Link().  We didn't use the 
        // extraSearchFields mechanism for this because it's not a property on 
        // all objects, so this is the most sensible place for it.
        if ( method_exists(get_class($item), 'Link') && ! in_array('Link', $fields) ) {
            $doc->add(
                new java("org.apache.lucene.document.Field",
                    'Link', 
                    $item->Link(), 
                    java('org.apache.lucene.document.Field$Store')->YES, 
                    java('org.apache.lucene.document.Field$Index')->NO
                )
            );
        }
        $this->getIndexWriter()->addDocument($doc);
    }

    /**
     * Queries the search engine and returns results.
     * All dataobjects have two additional properties added:
     *   - LuceneRecordID: the 'hit ID' which can be used to delete the record
     *     from the database.
     *   - LuceneScore: the 'score' assigned to the result by Lucene.
     * An additional property 'totalHits' is set on the DataObjectSet, showing 
     * how many hits there were in total.
     * @param $query_string (String) The query to send to the search engine.
     * @return (DataObjectSet) A DataObjectSet of DataObject search results. 
     *          An additional property 'totalHits' is set on the DataObjectSet.
     */
    public function find($query_string) {
        $version = Java('org.apache.lucene.util.Version')->LUCENE_33;
        $query_parser = new java('org.apache.lucene.queryParser.QueryParser',
            $version,
            'Title',
            new java('org.apache.lucene.analysis.standard.StandardAnalyzer', $version)
        );
        $query = $query_parser->parse($query_string);

        // Get our results...
        $index_dir = new Java(
            'org.apache.lucene.store.SimpleFSDirectory',
            new Java('java.io.File', $this->frontend->getIndexDirectoryName())
        );
        $searcher = new java("org.apache.lucene.search.IndexSearcher", $index_dir);
        $top_docs = $searcher->search($query, 100);
        
        // Create our result output set.
        $out = Object::create('DataObjectSet');
        foreach( java_values($top_docs->scoreDocs) as $score_doc ) {
            $doc = $searcher->doc($score_doc->doc);
            $obj = DataObject::get_by_id(
                java_values($doc->get('ClassName')), 
                java_values($doc->get('ObjectID'))
            );
            if ( ! $obj ) continue;
            $out->push($obj);
        }
        $searcher->close();
        
        $out->totalHits = java_values($top_docs->totalHits);
        
        return $out;
    }
    
    /**
     * Deletes a DataObject from the search index.
     * @param $item (DataObject) The item to delete.
     */
    public function delete($item) {
        $this->doDelete($item);
        $this->commit();
        $this->close();
    }

    public function doDelete($item) {
        $version = Java('org.apache.lucene.util.Version')->LUCENE_33;
        $query_parser = new java('org.apache.lucene.queryParser.QueryParser',
            $version,
            'Title',
            new java('org.apache.lucene.analysis.standard.StandardAnalyzer', $version)
        );
        $query = $query_parser->parse('ObjectID:'.$item->ID.' ClassName:'.$item->ClassName);

        $this->getIndexWriter()->deleteDocuments($query);    
    }
    
    public function wipeIndex() {
        $this->getIndexWriter()->deleteAll();
        $this->commit();
        $this->close();
    }

    //////////     Java-specific helper functions

    public function numDocs() {
        return java_values($this->getIndexWriter()->numDocs());
    }

    public function commit() {
        return java_values($this->getIndexWriter()->commit());
    }

    public function optimize() {
        return java_values($this->getIndexWriter()->optimize());
    }

    public function close() {
        if ( $this->indexWriter === null ) return;
        $this->indexWriter->close();
        $this->indexWriter = null;
    }

    protected function startStandalone() {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $JAVA="javaw";
        } else {
            $JAVA="nohup java";
        }
        $cwd = getcwd();
        chdir( LUCENE_BASE_PATH.'/java' );
        system("$JAVA -Dphp.java.bridge.daemon='true' -jar JavaBridge.jar SERVLET_LOCAL:".$this->getConfig('servlet_port')." &");
        chdir($cwd);    
    }
    
    /**
     * Always close() your indexwriter before the script exits!!!  Can't do it 
     * in the destructor...  :-(
     */
    protected function &getIndexWriter($wipe = false) {        
        if ( $this->indexWriter === null ) {
            $version = Java('org.apache.lucene.util.Version')->LUCENE_33;
            $analyzer = new java('org.apache.lucene.analysis.standard.StandardAnalyzer', $version); 
            $conf = new Java(
                'org.apache.lucene.index.IndexWriterConfig',
                $version,
                $analyzer
            );
            if ( $wipe ) {
                $conf->setOpenMode( java('org.apache.lucene.index.IndexWriterConfig$OpenMode')->CREATE );
            } else {
                $conf->setOpenMode( java('org.apache.lucene.index.IndexWriterConfig$OpenMode')->CREATE_OR_APPEND );
            }
            $index_dir = new Java(
                'org.apache.lucene.store.SimpleFSDirectory',
                new Java('java.io.File', $this->frontend->getIndexDirectoryName())
            );
            $this->indexWriter =& new Java(
                'org.apache.lucene.index.IndexWriter',
                $index_dir, 
                $conf
            );
        }
        return $this->indexWriter;    
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
    protected function getJavaField($object, $fieldName) {
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
            return new java("org.apache.lucene.document.Field",
                $fieldName, 
                $value, 
                java('org.apache.lucene.document.Field$Store')->YES, 
                java('org.apache.lucene.document.Field$Index')->ANALYZED
            );
            return Zend_Search_Lucene_Field::Text($fieldName, $value, $encoding);
        }
        if ( $config['type'] == 'unindexed' ) {
            return new java("org.apache.lucene.document.Field",
                $fieldName, 
                $value, 
                java('org.apache.lucene.document.Field$Store')->YES, 
                java('org.apache.lucene.document.Field$Index')->NO
            );
        }
        if ( $config['type'] == 'keyword' ) {
            $keywordFieldName = $fieldName;
            if ( $keywordFieldName == 'ID' ) $keywordFieldName = 'ObjectID'; // Don't use 'ID' as it's used by Zend Lucene
            return new java("org.apache.lucene.document.Field",
                $keywordFieldName, 
                $value, 
                java('org.apache.lucene.document.Field$Store')->YES,
                java('org.apache.lucene.document.Field$Index')->NOT_ANALYZED
            );
        }
        // Default - index and store as unstored
        return new java("org.apache.lucene.document.Field",
            $fieldName, 
            $value, 
            java('org.apache.lucene.document.Field$Store')->NO,
            java('org.apache.lucene.document.Field$Index')->ANALYZED
        );
    }

}

