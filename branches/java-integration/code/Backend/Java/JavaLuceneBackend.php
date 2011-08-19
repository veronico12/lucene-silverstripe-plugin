<?php

class JavaLuceneBackend extends LuceneBackend {

    protected $indexWriter = null;

    protected $indexSearcher = null;

    protected $config;

    public static $default_config = array(
        'servlet_port' => 8080,
        // analyzer can be 'standard' or 'english'
        'analyzer' => 'standard'
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
     * All dataobjects returned have two additional properties added:
     *   - LuceneRecordID: the Lucene 'Document ID' which can be used to delete 
     *     the record from the database.
     *   - LuceneScore: the 'score' assigned to the result by Lucene.
     * An additional property 'totalHits' is set on the DataObjectSet, showing 
     * how many hits there were in total.
     * @param $query_string (String) The query to send to the search engine.  
     *          This could be a string, or it could be a org.apache.lucene.search.Query
     *          object.
     * @return (DataObjectSet) A DataObjectSet of DataObject search results. 
     *          An additional property 'totalHits' is set on the DataObjectSet.
     */
    public function find($query_string) {
        return $this->findWithSort($query_string, 'score');
    }

    /**
     * Queries the search engine and returns results, with sorting.
     * All dataobjects returned have two additional properties added:
     *   - LuceneRecordID: the Lucene 'document ID' which can be used to delete 
     *     the record from the database.
     *   - LuceneScore: the 'score' assigned to the result by Lucene.
     * An additional property 'totalHits' is set on the DataObjectSet, showing 
     * how many hits there were in total.
     * @param $query_string (String) The query to send to the search engine.  
     *          This could be a string, or it could be a org.apache.lucene.search.Query
     *          object.
     * @param $sort (Mixed) This could either be the name of a field to 
     *          sort on, or a org.apache.lucene.search.Sort object.  You can 
     *          sort by the Lucene 'score' field to order things by relevance.
     * @param $reverse (Boolean) If a field name string is used for $sort, 
     *          determines whether the results will be ordered in normal or 
     *          reverse order.  If a org.apache.lucene.search.Sort object is 
     *          used for $sort, this parameter is ignored - you should include
     *          all your sorting requirements in the $sort object.
     * @return (DataObjectSet) A DataObjectSet of DataObject search results. 
     *          An additional property 'totalHits' is set on the DataObjectSet.
     */
    public function findWithSort($query_string, $sort, $reverse=false) {

        $version = Java('org.apache.lucene.util.Version')->LUCENE_33;

        // Prepare query
        if ( is_string($query_string) ) {
            // We need to use PerFieldAnalyzerWrapper to catch Keyword fields
            $analyzer = new java('org.apache.lucene.analysis.PerFieldAnalyzerWrapper',
                $this->getAnalyzer()
            );
            $extendedClasses = Lucene::get_extended_classes();
            $addedClasses = array();
            foreach( $extendedClasses as $extendedClass ) {
                $extendedClass = singleton($extendedClass);
                $fields = $extendedClass->getSearchedVars();
                foreach( $fields as $field ) {
                    if ( in_array($field, $addedClasses) ) continue;  // no need to add twice
                    $config = $extendedClass->getLuceneFieldConfig($field);
                    if ( !isset($config['type']) || $config['type'] != 'keyword' ) continue;
                    $analyzer->addAnalyzer(
                        $field, 
                        new java('org.apache.lucene.analysis.KeywordAnalyzer')
                    );
                    $addedClasses[] = $field;
                }
            }
            $query_parser = new java('org.apache.lucene.queryParser.QueryParser',
                $version,
                'Title',
                $analyzer
            );
            $query = $query_parser->parse($query_string);
        } else {
            // We can pass in a java query object if we like - lets us do fancy stuff with 
            // the query API rather than building up Query Parser strings if we want to.
            $query =& $query_string;
        }

        // Prepare sort object if we're using a string fieldname
        if ( is_string($sort) ) {
            switch( $sort ) {
                case 'score':
                    $sortType = java('org.apache.lucene.search.SortField')->SCORE;
                break;
                case 'id':
                    $sortType = java('org.apache.lucene.search.SortField')->DOC;
                break;
                default:
                    $sortType = java('org.apache.lucene.search.SortField')->STRING;
                break;        
            }
            $sort = new java('org.apache.lucene.search.Sort', 
                new java('org.apache.lucene.search.SortField', $sort, $sortType, $reverse)
            );
        }

        // Get results
        $index_dir = new Java(
            'org.apache.lucene.store.SimpleFSDirectory',
            new Java('java.io.File', $this->frontend->getIndexDirectoryName())
        );
        $searcher = new java("org.apache.lucene.search.IndexSearcher", $index_dir);
        $top_docs = $searcher->search($query, 1000, $sort);

        // Create result output set
        $out = Object::create('DataObjectSet');
        $score_docs = java_values($top_docs->scoreDocs);
        foreach( $score_docs as $score_doc ) {
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
        $query_parser = new java('org.apache.lucene.queryParser.QueryParser',
            Java('org.apache.lucene.util.Version')->LUCENE_33,
            'Title',
            $this->getAnalyzer()
        );
        $query = $query_parser->parse('+ObjectID:'.$item->ID.' +ClassName:'.$item->ClassName);

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

    /**
     * Starts the Java standalone Lucene server.  
     * The port this runs on can be set using eg. $lucene->backend->setConfig(8081)
     * to avoid colliding with Tomcat or another server running on port 8080.
     */
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
     * Creates an analyzer according to the config
     */
    protected function getAnalyzer() {
        $version = Java('org.apache.lucene.util.Version')->LUCENE_33;
        switch ( $this->config['analyzer'] ) {
            default:
            case 'standard':
                $analyzer = new java('org.apache.lucene.analysis.standard.StandardAnalyzer', $version);
            break;
            case 'english':
                $analyzer = new java('org.apache.lucene.analysis.en.EnglishAnalyzer', $version);
            break;
        }
        return $analyzer;
    }
    
    /**
     * Always close() your indexwriter before the script exits!!!  Can't do it 
     * in the destructor...  :-(
     */
    protected function &getIndexWriter($wipe = false) {        
        if ( $this->indexWriter === null ) {
            $version = Java('org.apache.lucene.util.Version')->LUCENE_33;
            $conf = new Java(
                'org.apache.lucene.index.IndexWriterConfig',
                $version,
                $this->getAnalyzer()
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

