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
    );

    public static $default_config = array(
        'encoding' => 'utf-8',
        'search_cache' => false,
        'index_dir' => TEMP_FOLDER,
        'index_name' => 'Lucene'
    );

    public $config = null;

    /**
     * Enable the default configuration of Zend Search Lucene searching on the 
     * given data classes.
     * 
     * @param   Array   $searchableClasses  An array of classnames to scan.  Can 
     *                                      choose from SiteTree and/or File.
     *                                      To not scan any classes, for example
     *                                      if we will define custom fields to scan,
     *                                      pass in an empty array.
     *                                      Defaults to scan SiteTree and File.
     */
    public static function enable($searchableClasses = null) {
        // We can't enable the search engine if we don't have QueuedJobs installed.
        if ( ! ClassInfo::exists('QueuedJobService') ) {
            die('<strong>'._t('ZendSearchLucene.ERROR','Error').'</strong>: '
                ._t('ZendSearchLucene.QueuedJobsRequired',
                'Lucene requires the Queued Jobs module.  See '
                .'<a href="http://www.silverstripe.org/queued-jobs-module/">'
                .'http://www.silverstripe.org/queued-jobs-module/</a>.')
            );
        }
        // These fields will get scanned by default on SiteTree and File
        $defaultColumns = array(
		        'SiteTree' => 'Title,MenuTitle,Content,MetaTitle,MetaDescription,MetaKeywords',
		        'File' => 'Filename,Title,Content'
    	  );
        // Set up include path, so we can find the Zend stuff
        set_include_path(
            dirname(__FILE__) . PATH_SEPARATOR . get_include_path()
        );
        if ( $searchableClasses === null ) $searchableClasses = array();
        if(!is_array($searchableClasses)) $searchableClasses = array($searchableClasses);
        foreach($searchableClasses as $class) {
            if(isset($defaultColumns[$class])) {
                Object::add_extension($class, "LuceneSearchable('".$defaultColumns[$class]."')");
            } else {
                user_error("I don't know the default search columns for class '$class'");
                return;
            }
        }
        Object::add_extension('ContentController', 'LuceneContentController');
        DataObject::add_extension('SiteConfig', 'LuceneSiteConfig');
        Object::add_extension('LeftAndMain', 'LuceneCMSDecorator');
        Object::add_extension('StringField', 'LuceneTextHighlightDecorator');
        // Set up default encoding and analyzer
        
        // Add the /Lucene/xxx URLs
        Director::addRules(
            100, 
            array( 'Lucene' => 'LeftAndMain' )
        );
    }

    //////////     Configuration methods

    /**
     * The singleton instance.
     * @static
     */
    public static $instance = null;

    /**
     * Get the singleton instance. 
     * We should be able to use multiple instances; need to re-code so that we 
     * can still configure indexes separately without having to completely 
     * configure each time we instantiate. 
     */   
    public static function singleton() {
        if ( self::$instance === null ) {
            self::$instance = new Lucene();
        }
        return self::$instance;
    }

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
     * Will cache the results if the search_cache config option is set.
     * @return DataObjectSet
     */
    public function find($query_string) {
        $hits = false;
        if ( $this->getConfig('search_cache') ) {
            $hits = SearchCache::getCached($query);
        }
        if ( $hits === false ) {
            $hits = $this->backend->find($query_string);
        }
        if ( $this->getConfig('search_cache') ) {
            SearchCache::cache($query, $hits);
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

