<?php

/** 
 * This class stores the user's recent searches in their session, so we can 
 * recall search result sets easily.
 * It is possible to crash this by caching a dataset bigger than the available
 * memory on the server.
 */

class SearchCache {

    /**
     * Store a DataObjectSet in the cache as a list of classnames/IDs.
     * @param $query (String) The query string this search was for.
     * @param 
     */
    public function cache($query, $dataobjectset) {
        $ids = array();
        foreach( $dataobjectset as $obj ) {
            $ids[] = $obj->class . ':' . $obj->ID;
        }
        $cache = SS_Cache::factory('foo') ;
        // implement
    }
    
    public function getCached($query) {
/* Reimplement with DataObjectSet returned and SS_Cache used
        $hits = Session::get(self::hash($query));
        if ( !$hits ) return false;
        $ids = unserialize($hits);
        $docs = array();
        $idx = ZendSearchLuceneWrapper::getIndex();
        foreach( $ids as $id ) {
            $doc = new Zend_Search_Lucene_Search_QueryHit($idx);
            $doc->id = $id[0];
            $doc->score = $id[1];
            $docs[] = $doc;
        }
        return $docs;
*/
    }

    private function hash($query) {
        $hash = 'search_'.md5( serialize($query) );
        return $hash;
    }

}

