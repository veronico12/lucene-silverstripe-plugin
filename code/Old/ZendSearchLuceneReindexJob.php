<?php

/**
 * The job description class for reindexing the search index via the Queued Jobs 
 * SilverStripe module.
 *
 * @package lucene-silverstripe-module
 * @author Darren Inwood <darren.inwood@chrometoaster.com>
 */
class ZendSearchLuceneReindexJob extends AbstractQueuedJob implements QueuedJob {

    public function __construct() {
        // Make sure db is set up for large blobs of data...
        mysql_query('SET GLOBAL net_buffer_length='.round(QueuedJobService::$memory_limit/2000));
        mysql_query('SET GLOBAL max_allowed_packet='.round(QueuedJobService::$memory_limit/2));
    }

    public function getTitle() {
        return _t('ZendSearchLucene.ReindexJobTitle', 'Rebuild the Lucene search engine index');
    }

    public function getSignature() {
        return 'ZendSearchLuceneReindexJob';
    }

    public function setup() {
        // Wipe current index
        ZendSearchLuceneWrapper::getIndex(true);
        $this->jobData = ZendSearchLuceneWrapper::getAllIndexableObjects();
        $this->totalSteps = count($this->jobData);
        $this->currentStep = 0;
    }

    /**
     * Ensures that we have a big enough max_allowed_packet and net_buffer_length
     * to cope with large queries.
     */
    private function checkDatabase() {
    }

    public function process() {
		// if there's no more, we're done!
		if (!count($this->jobData)) {
			$this->isComplete = true;
			$idx = ZendSearchLuceneWrapper::getIndex();
			$idx->optimize();
			return;
		}
		
		$this->currentStep++;
		
		$item = array_shift($this->jobData);
	
		$obj = DataObject::get_by_id($item[0], $item[1]);

        ZendSearchLuceneWrapper::index($obj);

		if (!count($this->jobData)) {
			$this->isComplete = true;
			$idx = ZendSearchLuceneWrapper::getIndex();
			$idx->optimize();
			return;
		}    
    }

}


