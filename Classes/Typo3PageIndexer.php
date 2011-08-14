<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2009-2011 Ingo Renner <ingo.renner@dkd.de>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

require_once(t3lib_extMgm::extPath('elasticsearch') . '/Resources/Private/PHP/guzzle.phar');

/**
 * General frontend page indexer.
 *
 * @author	Ingo Renner <ingo.renner@dkd.de>
 * @author	Daniel Poetzinger <poetzinger@aoemedia.de>
 * @author	Timo Schmidt <schmidt@aoemedia.de>
 * @package	TYPO3
 * @subpackage	solr
 */
class Tx_Elasticsearch_Typo3PageIndexer {

	/**
	 * Frontend page object (TSFE).
	 *
	 * @var	tslib_fe
	 */
	protected $page = NULL;

	/**
	 *
	 * @var Tx_Extbase_Object_ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 * Content extractor to extract content from TYPO3 pages
	 *
	 * @var	Tx_Elasticsearch_Typo3PageContentExtractor
	 */
	protected $contentExtractor = NULL;

	/**
	 * URL to be indexed as the page's URL
	 *
	 * @var	string
	 */
	protected $pageUrl = '';

	/**
	 * The page's access rootline
	 *
	 * @var	Tx_Elasticsearch_Access_Rootline
	 */
	protected $pageAccessRootline = NULL;

	/**
	 * ID of the current page's Solr document.
	 *
	 * @var	string
	 */
	protected static $pageSolrDocumentId = ''; // TODO

	/**
	 * The Solr document generated for the current page.
	 *
	 * @var	Apache_Solr_Document
	 */
	protected static $pageSolrDocument = NULL; // TODO

	protected $elasticSearchConnection = NULL;

	/**
	 * Constructor for class tx_solr_Indexer
	 *
	 * @param	tslib_fe	$page The page to index
	 */
	public function __construct(tslib_fe $page) {
		$this->page        = $page;
		$this->pageUrl     = t3lib_div::getIndpEnv('TYPO3_REQUEST_URL');
	}

	public function initializeObject() {

		try {
			$this->initializeElasticsearchConnection();
		} catch (Exception $e) {
			$this->log($e->getMessage() . ' Error code: ' . $e->getCode(), 3);

				// TODO extract to a class "ExceptionLogger"
			if ($GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_elasticsearch.']['logging.']['exceptions']) {
				t3lib_div::devLog('Exception while trying to index a page', 'elasticsearch', 3, array(
					$e->__toString()
				));
			}
		}

		$this->contentExtractor = $this->objectManager->create(
			'Tx_Elasticsearch_Typo3PageContentExtractor',
			$this->page->content,
			$this->page->renderCharset
		);

		$this->pageAccessRootline = $this->objectManager->create(
			'Tx_Elasticsearch_Access_Rootline',
			''
		);

	}

	/**
	 * @param Tx_Extbase_Object_ObjectManagerInterface $objectManager
	 */
	public function injectObjectManager(Tx_Extbase_Object_ObjectManagerInterface $objectManager) {
		$this->objectManager = $objectManager;
	}

	/**
	 * Initializes the Solr server connection.
	 *
	 * @throws	Exception when no Solr connection can be established.
	 */
	protected function initializeElasticsearchConnection() {
		$this->elasticSearchConnection = new Guzzle\Service\Client('http://localhost:9200');
		//$response = $client->get('/resource.xml')->send();
		//throw new Exception("Solr not there");
	}

	/**
	 * Indexes a page.
	 *
	 * @return	boolean	TRUE after successfully indexing the page, FALSE on error
	 */
	public function indexPage() {
		$pageIndexed = FALSE;
		$documents   = array(); // this will become usefull as soon as when starting to index individual records instead of whole pages

		if (is_null($this->elasticSearchConnection)) {
				// intended early return as it doesn't make sense to continue
				// and waste processing time if the elasticsearch server isn't available
				// anyways
				// FIXME use an exception
			return $pageIndexed;
		}

		$pageDocument = $this->getPageDocument();
		//$pageDocument = $this->substitutePageDocument($pageDocument);
		self::$pageSolrDocument = $pageDocument;
		$documents[]  = $pageDocument;
		//$documents    = $this->getAdditionalDocuments($pageDocument, $documents);
		$this->processDocuments($documents);

		$pageIndexed = $this->addDocumentsToIndex($documents);

		return $pageIndexed;
	}

	/**
	 * Given a page id, returns a document representing that page.
	 *
	 * @return	Apache_Solr_Document	A documment representing the page
	 */
	protected function getPageDocument() {
		$document   = array();
		$site       = Tx_Elasticsearch_Site::getSiteByPageId($this->page->id);
		$cHash      = $this->filterInvalidContentHash($this->page->cHash);
		$pageRecord = $this->page->page;

		self::$pageSolrDocumentId = $documentId = Tx_Elasticsearch_Util::getPageDocumentId(
			$this->page->id,
			$this->page->type,
			$this->page->sys_language_uid,
			$this->getDocumentIdGroups(),
			$cHash
		);
		$document['id'] =          $documentId;
		$document['site'] =        $site->getDomain();
		$document['siteHash'] =    $site->getSiteHash();
		$document['appKey'] =      'EXT:elasticsearch';
		$document['type'] =        'pages';
		$document['contentHash'] = $cHash;

			// system fields
		$document['uid'] =      $this->page->id;
		$document['pid'] =      $pageRecord['pid'];
		$document['typeNum'] =  $this->page->type;
		$document['created'] =  $pageRecord['crdate'];
		$document['changed'] =  $pageRecord['tstamp'];
		$document['language'] = $this->page->sys_language_uid;

			// access
		$document['access'] =     (string) $this->pageAccessRootline;
		if ($this->page->page['endtime']) {
			$document['endtime'] = $pageRecord['endtime'];
		}

			// content
		$document['title'] =      $this->utf8encode($this->contentExtractor->getPageTitle());
		$document['subTitle'] =    $this->utf8encode($pageRecord['subtitle']);
		$document['navTitle'] =   $this->utf8encode($pageRecord['nav_title']);
		$document['author'] =      $this->utf8encode($pageRecord['author']);
		$document['description'] = $this->utf8encode($pageRecord['description']);
		$document['abstract'] =    $this->utf8encode($pageRecord['abstract']);
		$document['content'] =     $this->contentExtractor->getIndexableContent();
		$document['url'] =        $this->pageUrl;

			// keywords
		$keywords = array_unique(t3lib_div::trimExplode(
			',',
			$this->utf8encode($pageRecord['keywords'])
		));
		foreach ($keywords as $keyword) {
			$document['keywords'] = $keyword;
		}

			// content from several tags like headers, anchors, ...
		$tagContent = $this->contentExtractor->getTagContent();
		foreach ($tagContent as $fieldName => $fieldValue) {
			$document[$fieldName] = $fieldValue;
		}

		return $document;
	}

	/**
	 * Adds the collected documents to the Solr index.
	 *
	 * @param	array	$documents An array of Apache_Solr_Document objects.
	 */
	protected function addDocumentsToIndex(array $documents) {
		$documentsAdded = FALSE;

		if (!count($documents)) {
			return $documentsAdded;
		}

		try {
			$this->log('Adding ' . count($documents) . ' documents.', 0, $documents);

			foreach ($documents as $document) {
				$request = $this->elasticSearchConnection->put('/public/typo3/{{documentId}}', array('documentId' => $document['id']));
				$request->setBody(json_encode($document));
				$request->send();
			}

			$documentsAdded = TRUE;
		} catch (Exception $e) {
			$this->log($e->getMessage() . ' Error code: ' . $e->getCode(), 2);

			if ($GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_elasticsearch.']['logging.']['exceptions']) {
				t3lib_div::devLog('Exception while adding documents', 'solr', 3, array(
					$e->__toString()
				));
			}
		}

		return $documentsAdded;
	}

	/**
	 * Allows third party extensions to replace or modify the page document
	 * created by this indexer.
	 *
	 * @param	Apache_Solr_Document	$pageDocument The page document created by this indexer.
	 * @return	Apache_Solr_Document	An Apache Solr document representing the currently indexed page
	 * @todo fix this again
	 */
	protected function substitutePageDocument(Apache_Solr_Document $pageDocument) {
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['Indexer']['indexPageSubstitutePageDocument'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['Indexer']['indexPageSubstitutePageDocument'] as $classReference) {
				$substituteIndexer = t3lib_div::getUserObj($classReference);

				if ($substituteIndexer instanceof tx_solr_SubstitutePageIndexer) {
					$substituteDocument = $substituteIndexer->getPageDocument($pageDocument);

					if ($substituteDocument instanceof Apache_Solr_Document) {
						$pageDocument = $substituteDocument;
					} else {
						// TODO throw an exception
					}
				} else {
					// TODO throw an exception
				}
			}
		}

		return $pageDocument;
	}

	/**
	 * Allows third party extensions to provide additional documents which
	 * should be indexed for the current page.
	 *
	 * @param	Apache_Solr_Document	$pageDocument The main document representing this page.
	 * @param	array	$existingDocuments An array of documents already created for this page.
	 * @return	array	An array of additional Apache_Solr_Document objects to index
	 * @todo fix this again
	 */
	protected function getAdditionalDocuments(Apache_Solr_Document $pageDocument, array $existingDocuments) {
		$documents = $existingDocuments;

		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['Indexer']['indexPageAddDocuments'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['Indexer']['indexPageAddDocuments'] as $classReference) {
				$additionalIndexer = t3lib_div::getUserObj($classReference);

				if ($additionalIndexer instanceof tx_solr_AdditionalIndexer) {
					$additionalDocuments = $additionalIndexer->getAdditionalDocuments($pageDocument, $documents);

					if (is_array($additionalDocuments)) {
						$documents = array_merge($documents, $additionalDocuments);
					}
				} else {
					// TODO throw an exception
				}
			}
		}

		return $documents;
	}

	/**
	 * Sends the given documents to the field processing service which takes
	 * care of manipulating fields as defined in the field's configuration.
	 *
	 * @param	array	An array of documents to manipulate
	 */
	protected function processDocuments(array $documents) {
		if (is_array($GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.']['index.']['fieldProcessingInstructions.'])) {
			$service = t3lib_div::makeInstance('tx_solr_fieldprocessor_Service');
			$service->processDocuments(
				$documents,
				$GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.']['index.']['fieldProcessingInstructions.']
			);
		}
	}


	// Logging
	// TODO replace by a central logger


	/**
	 * Logs messages to devlog and TS log (admin panel)
	 *
	 * @param	string		Message to set
	 * @param	integer		Error number
	 * @return	void
	 */
	protected function log($message, $errorNum = 0, array $data = array()) {
		if (is_object($GLOBALS['TT'])) {
			$GLOBALS['TT']->setTSlogMessage('tx_solr: ' . $message, $errorNum);
		}

		if ($GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_elasticsearch.']['logging.']['indexing']) {
			if (!empty($data)) {
				$logData = array();
				foreach ($data as $value) {
					$logData[] = (array) $value;
				}
			}

			t3lib_div::devLog($message, 'solr', $errorNum, $logData);
		}
	}


	// Misc


	/**
	 * Checks whether a given string is a valid cHash.
	 * If the hash is valid it will be returned as is, an empty string will be
	 * returned otherwise.
	 *
	 * @param	string	The cHash to check for validity
	 * @return	string	The passed cHash if valid, an empty string if invalid
	 * @see tslib_fe->makeCacheHash
	 */
	protected function filterInvalidContentHash($cHash) {
		$urlParameters   = t3lib_div::_GET();
		$cHashParameters = t3lib_div::cHashParams(t3lib_div::implodeArrayForUrl('', $urlParameters));

		$calculatedCHash = t3lib_div::calculateCHash($cHashParameters);

		return ($calculatedCHash == $cHash) ? $cHash : '';
	}

	/**
	 * Gets the current indexer mode.
	 *
	 * @return	string	Either tx_solr_IndexerSelector::INDEXER_STRATEGY_FRONTEND or tx_solr_IndexerSelector::INDEXER_STRATEGY_QUEUE
	 */
	public function getIndexerMode() {
		return $this->indexerMode;
	}

	/**
	 * Gets the current page's URL.
	 *
	 * Depending on the current indexer mode, Frontend or IndexQueue different
	 * ways of retrieving the URL are chosen.
	 *
	 * @return	string	URL of the current page.
	 */
	public function getPageUrl() {
		return $this->pageUrl;
	}

	/**
	 * Sets the URL to use for the page document.
	 *
	 * @param	string	$url The page's URL.
	 */
	public function setPageUrl($url) {
		if (filter_var($url, FILTER_VALIDATE_URL)) {
			$this->pageUrl = $url;
		}
	}

	/**
	 * Gets the page's access rootline.
	 *
	 * @return	tx_solr_access_Rootline The page's access rootline
	 */
	public function getPageAccessRootline() {
		return $this->pageAccessRootline;
	}

	/**
	 * Sets the page's access rootline.
	 *
	 * @param	Tx_Elasticsearch_Access_Rootline	$accessRootline The page's access rootline
	 */
	public function setPageAccessRootline(Tx_Elasticsearch_Access_Rootline $accessRootline) {
		$this->pageAccessRootline = $accessRootline;
	}

	/**
	 * Gets the current page's Solr document ID.
	 *
	 * @return	string|NULL	The page's Solr document ID or NULL in case no document was generated yet.
	 */
	public static function getPageSolrDocumentId() {
		return self::$pageSolrDocumentId;
	}

	/**
	 * Gets the Solr document generated for the current page.
	 *
	 * @return	Apache_Solr_Document|NULL The page's Solr document or NULL if it has not been generated yet.
	 */
	public static function getPageSolrDocument() {
		return self::$pageSolrDocument;
	}

	/**
	 * Gets a comma separated list of frontend user groups to use for the
	 * document ID.
	 *
	 * @return	string	A comma separated list of frontend user groups.
	 */
	protected function getDocumentIdGroups() {
		$groups = $this->pageAccessRootline->getGroups();
		$groups = Tx_Elasticsearch_Access_Rootline::cleanGroupArray($groups);

		if (empty($groups)) {
			$groups[] = 0;
		}

		$groups = implode(',', $groups);

		return $groups;
	}

	/**
	 * Helper method to utf8 encode (and trim) a string.
	 *
	 * @param	string	$string String to utf8 encode, can be utf8 already, won't be touched then.
	 * @return	string	utf8 encoded string.
	 */
	protected function utf8encode($string) {
		$utf8EncodedString = $this->page->csConvObj->utf8_encode(
			trim($string),
			$this->page->renderCharset
		);

		return $utf8EncodedString;
	}
}
?>