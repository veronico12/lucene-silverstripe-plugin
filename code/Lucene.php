<?php

class Lucene extends Object {

    /**
     * The backend to use.  Must be an implementation of LuceneWrapperInterface.
     * @static
     */
    protected $backend;

    /**
     * Configuration for this Lucene instance.
     */
    protected $config = array(
        'encoding' => 'utf-8',
        'session_search_cache' => false
    );

    public static $default_config = array(
        'use_session_cache' => false,
        'index_dir' => TEMP_FOLDER,
        'index_name' => 'Lucene'
    );

    public $config = null;

    /**
     * Does all the object decorating/extending etc.
     */
    public static function enable() {
        
    }

    //////////     Configuration methods

    /**
     * Automatically sets the backend according to the server's capabilities.
     * Will use the Java backend if possible, otherwise will fall back to the 
     * Zend backend.
     */
    protected function __construct($config = null, &$backend = null) {
        if ( ! is_array($config) ) $config = array();
        $this->config = array_merge(self::$default_config, $config);
        if ( $backend === null ) {
            if ( extension_loaded('java') && ini_get('allow_url_fopen') && ini_get('allow_url_include') ) {
                $backend = new JavaLuceneBackend($this);
            } else {
                $backend = new ZendLuceneBackend($this);
            }
        }
        $this->backend =& $backend;
    }

    public function setBackend(&$backend) {
        $this->backend =& $backend;
    }

    public function &getBackend($backend) {
        return $this->backend;
    }

    public function setConfig($key, $value) {
        $this->config[$key] = $value;
    }

    public function getConfig($key) {
        if ( ! isset($this->config[$key]) ) return null;
        return $this->config[$key];    
    }

    public function getIndexDirectoryName() {
        return $this->getConfig('index_dir') . '/' . $this->getCOnfig('index_name');
    }
    
    //////////     Runtime methods

    /**
     * Index an object.
     */
    public function index($item) {
        if ( ! Object::has_extension($item->ClassName, 'LuceneSearchable') ) {
            return;
        }
        return $this->backend->index($item);
    }
    
    /**
     * Get hits for a query.
     * Will cache the results if the session_search_cache config option is set.
     * @return DataObjectSet
     */
    public function find($query_string) {
        $hits = false;
        if ( $this->getConfig('session_search_cache') ) {
            $hits = SessionSearchCache::getCached($query);
        }
        if ( $hits === false ) {
            $hits = $this->backend->find($query_string);
        }
        if ( self::$useSessionCache ) {
            SessionSearchCache::cache($query, $hits);
        }
        return $hits;
    }

    public function delete($item) {
        if ( ! Object::has_extension($item->ClassName, 'LuceneSearchable') ) {
            return;
        }
        return $this->backend->delete($item);
    }

}

