<?php

/** 
 * This class stores the user's recent searches in their session, so we can 
 * recall search result sets easily.
 */

class SessionSearchCache {

    public static function cache($query, $hits) {
        $ids = array();
        foreach( $hits as $hit ) {
            $ids[] = array($hit->id, $hit->score);
        }
        Session::set(self::hash($query), serialize($ids));
    }
    
    public static function getCached($query) {
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
    }

    private static function hash($query) {
        if ( ! is_string($query[0]) ) {
            try {
                $query[0] = $query[0]->rewrite(ZendSearchLuceneWrapper::getIndex());
            } catch (Exception $e) {
                $query[0] = serialize($query);
            }
        }
        $hash = 'search_'.md5( serialize($query) );
        return $hash;
    }

}

