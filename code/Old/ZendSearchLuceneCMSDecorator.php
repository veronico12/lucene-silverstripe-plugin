<?php

/**
 * Adds functions to LeftAndMain to rebuild the Lucene search index.
 *
 * @package lucene-silverstripe-module
 * @author Darren Inwood <darren.inwood@chrometoaster.com>
 */
class ZendSearchLuceneCMSDecorator extends LeftAndMainDecorator {

    /**
     * Enables the extra button added via ZendSearchLuceneSiteConfig.
     * @static
     * @access public
     */
    public static $allowed_actions = array(
        'rebuildZendSearchLuceneIndex',
        'reindex',
        'diagnose',
        'search'
    );

    /**
     * Receives the form submission which tells the index rebuild process to 
     * begin.
     *
     * @access public
     * @return      String          The AJAX response to send to the CMS.
     */
    public function rebuildZendSearchLuceneIndex() {
        ZendSearchLuceneWrapper::rebuildIndex();
        FormResponse::status_message( 
            _t('ZendSearchLucene.SuccessMessage', 'A Lucene search index rebuild job has been added to the Jobs queue.'),
            'good'
        );
        return FormResponse::respond();
    }

    /**
     * Debug method to allow manual reindexing with output via the URL 
     * /Lucene/reindex
     *
     * @access public
     * Note that this should NOT be used as a reindexing
     * process in production, as it doesn't allow for out of memory or script 
     * execution time problems.
     */
    public function reindex() {
        set_time_limit(600);
        $start = microtime(true);
        echo '<h1>Reindexing</h1>'."\n"; flush();
        echo 'Note that this process may die due to time limit or memory '
            .'exhaustion, and is purely for debugging purposes.  Use the '
            .'Queued Jobs reindex process for production indexing.'
            ."<br />\n<br />\n"; flush();
        ZendSearchLuceneWrapper::getIndex(true);
        $indexable = ZendSearchLuceneWrapper::getAllIndexableObjects();
        foreach( $indexable as $item ) {
            $obj = DataObject::get_by_id($item[0], $item[1]);
            if ( $obj ) {
                $obj_start = microtime(true);
                echo $item[0].' '.$item[1].' ('.$obj->class.')'; flush();
                ZendSearchLuceneWrapper::index($obj);
                echo ' - '.round(microtime(true)-$obj_start, 3).' seconds'."<br />\n"; flush();
            } else {
                echo 'Object '.$item[0].' '.$item[1].' was not found.'."<br />\n"; flush();
            }
        }
        echo "<br />\n".'Finished ('.round(microtime(true)-$start, 3).' seconds)'."<br />\n"; flush();
    }

    /**
     * Method for testing search
     */
    public function search() {
        $index = ZendSearchLuceneWrapper::getIndex();
        
        $hits =  $index->find('Title:personal');
        var_dump( count($hits) );
        foreach( $hits as $hit ) {
            var_dump( $hit->Title );
        }
    }

    /**
     * Method for testing config
     */
    public function diagnose() {
        echo '<h1>Lucene Diagnosis</h1>';
        echo '<hr /><h2>Dependencies</h2>';
        if ( ! file_exists(Director::baseFolder().'/queuedjobs') ) {
            echo '<p>The <strong>Queued Jobs</strong> module is not installed.  Reindexing will not work.</p>';
            echo '<p>Please install this module to enable Lucene.</p>';
            echo '<p><a href="http://www.silverstripe.org/queued-jobs-module/">Queued Jobs</a></p>';
        } else {
            echo '<p>The <strong>Queued Jobs</strong> module is installed.</p>';
        }        
        echo '<hr /><h2>Installed programs/extensions</h2>';
        // catdoc - scan older MS documents
        $catdoc = false;
        if ( defined('CATDOC_BINARY_LOCATION') && file_exists(CATDOC_BINARY_LOCATION) ) {
            $catdoc = CATDOC_BINARY_LOCATION;
        } else if ( file_exists('/usr/bin/catdoc') ) {
            $catdoc = '/usr/bin/catdoc';
        } else if ( file_exists('/usr/local/bin/catdoc') ) {
            $catdoc = '/usr/local/bin/catdoc';
        }
        if ( $catdoc ) {
            echo '<p>Utility <strong>catdoc</strong> is installed at '.$catdoc.' - older MS Office documents (.doc, .xls, .ppt) will be scanned.</p>';
        } else {
            echo '<p>Utility <strong>catdoc</strong> is not installed.  Older MS Office documents (.doc, .xls, .ppt) will not be scanned.</p>';
        }
        // zip - scan newer MS documents
        if ( extension_loaded('zip') ) {
            echo '<p>PHP extension <strong>zip</strong> is installed - newer MS Office documents (.docx, .xlsx, .pptx) will be scanned.</p>';
        } else {
            echo '<p>PHP extension <strong>zip</strong> is not installed - newer MS Office documents (.docx, .xlsx, .pptx) will not be scanned.</p>';
        }
        // pdftotext - scan PDF documents
        $pdftotext = false;
        if ( defined('PDFTOTEXT_BINARY_LOCATION') ) {
            $pdftotext = PDFTOTEXT_BINARY_LOCATION;
        } else if ( file_exists('/usr/bin/pdftotext') ) {
            $pdftotext = '/usr/bin/pdftotext';
        } else if ( file_exists('/usr/local/bin/pdftotext') ) {
            $pdftotext = '/usr/local/bin/pdftotext';
        }
        if ( $pdftotext ) {
            echo '<p>Utility <strong>pdftotext</strong> is installed at '.$pdftotext.'.  PDF documents will be scanned.</p>';
        } else {
            if ( extension_loaded('zlib') ) {
                echo '<p>Utility <strong>pdftotext</strong> is not installed, but the PDF2Text class will be used to scan PDF documents.</p>';
            } else {
                echo '<p>Utility <strong>pdftotext</strong> is not installed, and PHP extension <strong>zlib</strong> is not loaded.  '
                    .'PDF documents using gzip compression will not be scanned.  Other PDF documents will be scanned using the PDF2Text class.</p>';
            }
        }
        echo '<hr /><h2>Index</h2>';
        $idx = ZendSearchLuceneWrapper::getIndex();
        echo '<p>Number of records in the index: '.$idx->count().'</p>';
        echo '<p>Number of records in the index (excluding deleted records): '.$idx->numDocs().'</p>';
        echo '<hr /><h2>Database setup</h2>';
        $max_packet = mysql_fetch_object( 
            mysql_query('SELECT @@max_allowed_packet AS size') 
        );
        echo '<p>Your MySQL max_allowed_packet value is '.$max_packet->size.'.<br/>';
        if ( $max_packet->size >= 128 * 1024 * 1024 ) {
            echo 'This should be high enough to cope with large datasets.';
        } else {
            echo 'This may cause issues with large datasets.</p>';
            echo '<p>To rectify this, you can add the following lines to functions that may create large datasets, eg. search actions:</p>';
            echo '<pre>'
            .'mysql_query(\'SET GLOBAL net_buffer_length=1000000\');'."\n"
            .'mysql_query(\'SET GLOBAL max_allowed_packet=1000000000\');</pre>';
            echo '<p>Alternatively, you can set these config values in your MySQL server config file.';
        }
        echo '</p>';
        $log_bin = mysql_fetch_object( 
            mysql_query('SELECT @@log_bin AS log_bin') 
        );
        if ( $log_bin->log_bin == 0 ) {
            echo '<p>Your MySQL server is set to not use the binary log.<br/>'
            .'This is the correct setting.</p>';
        } else {
            echo '<p>Your MySQL server is set to use the binary log.<br/>'
            .'This will result in a large amount of disk space being used for '
            .'logging Lucene operations, which can use many GB of space with '
            .'large datasets.</p>';
            echo '<p>To rectify this, you can add the following lines to your _config.php:</p>';
            echo '<pre>'
            .'mysql_query(\'SET GLOBAL log_bin=0\');'."\n"
            .'</pre>';
            echo '<p>Alternatively, you can set this config value in your MySQL server config file.';            
        }

        $classes = ClassInfo::subclassesFor('DataObject');
        foreach( $classes as $class ) {
            if ( ! Object::has_extension($class, 'ZendSearchLuceneSearchable') ) continue;
            $class_config = singleton($class)->getLuceneClassConfig();
            echo '<hr/><h2>'.$class.'</h2>';
            echo '<h3>Class config</h3>';
            Debug::dump( $class_config );
            echo '<h3>Field config</h3>';
            foreach( singleton($class)->getSearchedVars() as $fieldname ) {
                echo '<h4>'.$fieldname.'</h4>';
                if ( $fieldname == 'Link' ) echo '<p>No output means that Link is not indexed for this class.</p>';
                @Debug::dump( singleton($class)->getLuceneFieldConfig($fieldname) );
            }
        }
        


    }

}

