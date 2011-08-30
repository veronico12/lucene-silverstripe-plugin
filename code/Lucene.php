<?php

class Lucene extends Object {

    /**
     * The backend to use.  Must be an implementation of LuceneWrapperInterface.
     * @static
     */
    public $backend;

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

    /**
     * The singleton instance.
     * @static
     */
    public static $instance = null;

    /**
     * Registry of extended classes.  Access via Lucene::get_extended_classes()
     * @static
     */
    protected static $extended_classes = null;

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
        Object::add_extension('StringField', 'TextHighlightDecorator');

        // Add the /Lucene/xxx URLs
        Director::addRules(
            100, 
            array( 'Lucene' => 'LeftAndMain' )
        );
    }

    //////////     Configuration methods

    /**
     * Get the singleton instance. 
     * We should be able to use multiple instances; need to re-code so that we 
     * can still configure indexes separately without having to completely 
     * configure each time we instantiate.  Perhaps some sort of factory plus 
     * config registry.
     */   
    public static function singleton() {
        if ( self::$instance === null ) {
            self::$instance = new Lucene();
        }
        return self::$instance;
    }

    /**
     * Sets up a class to be searched by Lucene.  Using this method instead of 
     * Object::add_extension directly allows us to keep a registry of configured 
     * classes, which speeds searches up.
     *
     * It also allows us to configure classes without needing to use horrible 
     * nested quotes in strings or elaborate json_encoded arrays.
     */
    public static function add_extension($className, $config = null) {
        if ( $config === null ) {
            $config = '';
        } elseif ( is_array($config) ) {
            $config = "'".json_encode($config)."'";
        } elseif ( is_string($config) ) {
            $config = "'".$config."'";
        }
        Object::add_extension($className, "LuceneSearchable(".$config.")");
        Lucene::$extended_classes[] = $className;
    }

    public static function get_extended_classes() {
        return Lucene::$extended_classes;
    }

    /**
     * Automatically sets the backend according to the server's capabilities.
     */
    public function __construct($config = null, &$backend = null) {
        if ( ! is_array($config) ) $config = array();
        $this->config = array_merge(self::$default_config, $config);
        if ( $backend === null ) {
            if ( ini_get('allow_url_fopen') && ini_get('allow_url_include') ) {
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
        return $this->getConfig('index_dir') . '/' . $this->getConfig('index_name');
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
     *
     * @param $query_string (String) The Lucene query language string to search.
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

    /**
     * Get hits for a query, sorted by a specified field.
     * The field must be indexed.
     * Will cache the results if the search_cache config option is set.
     *
     * 
     * @param $query_string (String) The Lucene query language string to search.
     * @param $numResults (Integer) Number of results to return
     * @param $sort 
     * @return DataObjectSet
     */
    public function findWithSort($query_string, $sortField, $sortOrder) {
        $hits = false;
        if ( $this->getConfig('search_cache') ) {
            $hits = SearchCache::getCached($query);
        }
        if ( $hits === false ) {
            $hits = $this->backend->findWithSort($query_string, $sortField, $sortOrder);
        }
        if ( $this->getConfig('search_cache') ) {
            SearchCache::cache($query, $hits);
        }
        return $hits;
    }
     
    /**
     * Delete a DataObject from the search index.
     * @param $item (DataObject) The DataObject to remove from the index.
     */
    public function delete($item) {
        if ( ! Object::has_extension($item->ClassName, 'LuceneSearchable') ) {
            return;
        }
        return $this->backend->delete($item);
    }

    /**
     * Deletes all info in the index.
     */
    public function wipeIndex() {
        return $this->backend->wipeIndex();
    }

    public function commit() {
        return $this->backend->commit();
    }

    public function optimize() {
        return $this->backend->optimize();
    }

    public function close() {
        return $this->backend->close();
    }    

    /**
     * Returns a data array of all indexable DataObjects.  For use when reindexing.
     */
    public static function getAllIndexableObjects($className='DataObject') {
        $possibleClasses = ClassInfo::subclassesFor($className);
        $extendedClasses = array();
        foreach( $possibleClasses as $possibleClass ) {
            if ( Object::has_extension($possibleClass, 'LuceneSearchable') ) {
                $extendedClasses[] = $possibleClass;
            }
        }
        $indexed = array();
        foreach( $extendedClasses as $className ) {
            $config = singleton($className)->getLuceneClassConfig();
            $query = Object::create('SQLQuery');
            $baseClass = ClassInfo::baseDataClass($className);
            $query->select("\"$baseClass\".\"ID\"", "\"$baseClass\".\"ClassName\"");
            $query->from($className);
            if ( $baseClass != $className ) {
                $query->leftJoin($baseClass, "\"$className\".\"ID\" = \"$baseClass\".\"ID\"");
            }
            $filter = $config['index_filter'] ? $config['index_filter'] : '';
            $query->where($filter);
            $result = mysql_unbuffered_query($query->sql());
            if ( mysql_error() ) continue; // Can't index this one... ignore for now.
            while( $object = mysql_fetch_object($result) ) {
                if ( $object->ClassName === null || $object->ID === NULL ) continue;
                if ( ! in_array($object->ClassName, $extendedClasses) ) continue;
                // Only re-index if we haven't already indexed this DataObject
                if ( ! array_key_exists($object->ClassName.' '.$object->ID, $indexed) ) {
                    $indexed[$object->ClassName.' '.$object->ID] = array(
                        $object->ClassName, 
                        $object->ID
                    );
                }
            }
        }
        return $indexed;
    }

}

