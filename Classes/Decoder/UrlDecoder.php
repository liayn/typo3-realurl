<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2015 Dmitry Dulepov (dmitry.dulepov@gmail.com)
 *  All rights reserved
 *
 *  This script is part of the Typo3 project. The Typo3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
namespace DmitryDulepov\Realurl\Decoder;

use DmitryDulepov\Realurl\EncodeDecoderBase;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageRepository;

/**
 * This class contains URL decoder for the RealURL.
 *
 * @package DmitryDulepov\Realurl\Decoder
 * @author Dmitry Dulepov <dmitry.dulepov@gmail.com>
 */
class UrlDecoder extends EncodeDecoderBase {

	/** @var bool */
	protected $appendedSlash = FALSE;

	/** @var TypoScriptFrontendController */
	protected $caller;

	/** @var string */
	protected $mimeType = '';

	/** @var PageRepository */
	protected $pageRepository = NULL;

	/** @var string */
	protected $siteScript;

	/** @var string */
	protected $speakingUri;

	/** @var int */
	protected $sysLanguageUid = 0;

	/**
	 * Initializes the class.
	 */
	public function __construct() {
		parent::__construct();
		$this->siteScript = GeneralUtility::getIndpEnv('TYPO3_SITE_SCRIPT');
	}

	/**
	 * Decodes the URL. This function is called from \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController::checkAlternativeIdMethods()
	 *
	 * @param array $params
	 * @return void
	 */
	public function decodeUrl(array $params) {
		$this->caller = $params['pObj'];

		if ($this->isSpeakingUrl()) {
			$this->setSpeakingUriFromSiteScript();
			$this->checkMissingSlash();
			if ($this->speakingUri) {
				$this->setLanguageFromQueryString();
				$this->runDecoding();
			}
		}
	}

	/**
	 * Checks if the missing slash should be corrected.
	 *
	 * @return void
	 */
	protected function checkMissingSlash() {
		$this->speakingUri = rtrim($this->speakingUri, '?');

		$regexp = '~^([^\?]*[^/])(\?.*)?$~';
		if (preg_match($regexp, $this->speakingUri)) { // Only process if a slash is missing:
			$options = GeneralUtility::trimExplode(',', $this->configuration->get('init/appendMissingSlash'), true);
			if (in_array('ifNotFile', $options)) {
				if (!preg_match('/\/[^\/\?]+\.[^\/]+(\?.*)?$/', '/' . $this->speakingUri)) {
					$this->speakingUri = preg_replace($regexp, '\1/\2', $this->speakingUri);
					$this->appendedSlash = true;
				}
			}
			else {
				$this->speakingUri = preg_replace($regexp, '\1/\2', $this->speakingUri);
				$this->appendedSlash = true;
			}
			if ($this->appendedSlash && count($options) > 0) {
				foreach ($options as $option) {
					$matches = array();
					if (preg_match('/^redirect(\[(30[1237])\])?$/', $option, $matches)) {
						$code = count($matches) > 1 ? $matches[2] : 301;
						$status = 'HTTP/1.1 ' . $code . ' TYPO3 RealURL redirect M' . __LINE__;

						// Check path segment to be relative for the current site.
						// parse_url() does not work with relative URLs, so we use it to test
						if (!@parse_url($this->speakingUri, PHP_URL_HOST)) {
							@ob_end_clean();
							header($status);
							header('Location: ' . GeneralUtility::locationHeaderUrl($this->speakingUri));
							exit;
						}
					}
				}
			}
		}
	}

	/**
	 * Creates a page repository object if it does not exist yet.
	 *
	 * @return void
	 */
	protected function createPageRepository() {
		if (is_null($this->pageRepository)) {
			$this->pageRepository = GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\Page\\PageRepository');
			$this->pageRepository->init(FALSE);
		}
	}

	/**
	 * Creates query string from passed variables.
	 *
	 * @param array|null $getVars
	 * @return string
	 */
	protected function createQueryString($getVars) {
		if (!is_array($getVars) || count($getVars) == 0) {
			return $_SERVER['QUERY_STRING'];
		}

		$parameters = array();
		foreach ($getVars as $var => $value) {
			$parameters = array_merge($parameters, $this->createQueryStringParameter($value, $var));
		}

		// If cHash is provided in the query string, replace it in $getVars
		$cHashOverride = GeneralUtility::_GET('cHash');
		if ($cHashOverride) {
			$getVars['cHash'] = $cHashOverride;
		}

		$queryString = GeneralUtility::getIndpEnv('QUERY_STRING');
		if ($queryString) {
			array_push($parameters, $queryString);
		}

		return implode('&', $parameters);
	}


	/**
	 * Generates a parameter string from an array recursively
	 *
	 * @param array $parameters Array to generate strings from
	 * @param string $prependString path to prepend to every parameter
	 * @return array
	 */
	protected function createQueryStringParameter($parameters, $prependString = '') {
		if (!is_array($parameters)) {
			return array($prependString . '=' . $parameters);
		}

		if (count($parameters) == 0) {
			return array();
		}

		$paramList = array();
		foreach ($parameters as $var => $value) {
			$paramList = array_merge($paramList, $this->createQueryStringParameter($value, $prependString . '[' . $var . ']'));
		}

		return $paramList;
	}

	/**
	 * Decodes the path.
	 *
	 * @param array $pathSegments
	 * @return int
	 */
	protected function decodePath(array &$pathSegments) {
		$remainingPathSegments = $pathSegments;
		$result = $this->searchPathInCache($remainingPathSegments);

		if ($result === 0 || count($remainingPathSegments) > 0) {
			// Here we are if one of the following is true:
			// - nothing is in the cache
			// - there is an entry in the cache for the partial path
			// We see what it is:
			// - if a postVar exists for the next segment, it is a full path
			// - if no path segments left, we found the path
			// - otherwise we have to search

			reset($pathSegments);
			if (!$this->isPostVar(current($pathSegments))) {
				$this->createPageRepository();

				if ($result !== 0) {
					$processedPathSegments = array_diff($pathSegments, $remainingPathSegments);
					$currentPid = $result;
				} else {
					$processedPathSegments = array();
					$currentPid = $this->rootPageId;
				}
				while ($currentPid !== 0 && count($remainingPathSegments) > 0) {
					$segment = array_shift($remainingPathSegments);
					$currentPid = $this->searchPages($currentPid, $segment);
					if ($currentPid !== 0) {
						$result = $lastPidInCache = $currentPid;
						$processedPathSegments[] = $segment;
						// Path is valid so far, so we cache it
						$this->putToPathCache($result, implode('/', $processedPathSegments));
					}
					else {
						array_unshift($remainingPathSegments, $segment);
					}
				}
			}
		}
		if ($result !== 0) {
			$pathSegments = $remainingPathSegments;
		}

		return $result;
	}

	/**
	 * Decodes the URL.
	 *
	 * @param string $path
	 * @return array with keys 'id' and 'GET_VARS';
	 */
	protected function doDecoding($path) {
		$result = array('id' => 1, 'GET_VARS' => array());

		$pathSegments = explode('/', trim($path, '/'));
//		$result['GET_VARS'] = $this->decodeVariables($pathSegments, (array)$this->configuration->get('preVars'));
		$result['id'] = $this->decodePath($pathSegments);
		// TODO fixedPostVars are only valid for some pages, correct it on the line below!
//		ArrayUtility::mergeRecursiveWithOverrule($result['GET_VARS'], $this->decodeVariables($pathSegments, (array)$this->configuration->get('fixedPostVars')));
//		ArrayUtility::mergeRecursiveWithOverrule($result['GET_VARS'], $this->decodeVariables($pathSegments, (array)$this->configuration->get('postVarSets')));

		if (count($pathSegments) > 0) {
			reset($pathSegments);
			$this->throw404('"' . current($pathSegments) . '" could not be decoded from path.');
		}

		return $result;
	}

	/**
	 * Gets the entry from cache.
	 *
	 * @param string $speakingUrl
	 * @return array|null
	 */
	protected function getFromUrlCache($speakingUrl) {
		$row = $this->databaseConnection->exec_SELECTgetSingleRow('*', 'tx_realurl_urlcache',
			'rootpage_id=' . $this->rootPageId . ' AND ' .
				'speaking_url=' . $this->databaseConnection->fullQuoteStr($speakingUrl, 'tx_realurl_urlcache')
		);

		return is_array($row) ? (array)@json_decode($row['speaking_url_data']) : NULL;
	}

	/**
	 * Parses the URL and validates the result.
	 *
	 * @return array
	 */
	protected function getUrlParts() {
		$uParts = @parse_url($this->speakingUri);
		if (!is_array($uParts)) {
			$this->throw404('Current URL is invalid');
		}

		return $uParts;
	}

	/**
	 * Checks if the given segment is a name of the postVar.
	 *
	 * @param string $segment
	 * @return bool
	 */
	protected function isPostVar($segment) {
		$postVarNames = array_filter(array_keys((array)$this->configuration->get('postVarSets')));
		return in_array($segment, $postVarNames);
	}


	/**
	 * Checks if the current URL is a speaking URL.
	 *
	 * @return bool
	 */
	protected function isSpeakingUrl() {
		return $this->siteScript && substr($this->siteScript, 0, 9) !== 'index.php' && substr($this->siteScript, 0, 1) !== '?';
	}

	/**
	 * Adds data to the path cache.
	 *
	 * @param int $pageId
	 * @param string $pagePath
	 * @return void
	 */
	protected function putToPathCache($pageId, $pagePath) {
		$rootPageId = $this->configuration->get('pagePath/rootpage_id');
		$cacheEntry = $this->databaseConnection->exec_SELECTgetSingleRow('*', 'tx_realurl_pathcache',
			'page_id=' . (int)$pageId . ' AND language_id=' . (int)$this->sysLanguageUid .
				' AND rootpage_id=' . $rootPageId .
				' AND expire=0'
		);
		if (!is_array($cacheEntry) || $pagePath !== $cacheEntry['pagepath']) {
			$this->databaseConnection->exec_INSERTquery('tx_realurl_pathcache', array(
				'page_id' => $pageId,
				'language_id' => $this->sysLanguageUid,
				'rootpage_id' => $rootPageId,
				'mpvar' => '',
				'pagepath' => $pagePath,
				'expire' => 0,
			));
		}
	}

	/**
	 * Adds data to the url cache. This must run after $this->setRequestVariables().
	 *
	 * @param array $cacheInfo
	 * @return void
	 */
	protected function putToUrlCache(array $cacheInfo) {
		$getVars = $_GET;
		$getVars['id'] = $this->caller->id;
		$this->sortArrayDeep($getVars);
		$originalUrl = trim(GeneralUtility::implodeArrayForUrl('', $getVars), '&');
		$this->databaseConnection->exec_INSERTquery('tx_realurl_urlcache', array(
			'crdate' => time(),
			'page_id' => $this->caller->id,
			'rootpage_id' => $this->rootPageId,
			'original_url' => $originalUrl,
			'speaking_url' => $this->speakingUri,
			'speaking_url_data' => json_encode($cacheInfo)
		));
	}

	/**
	 * Contains the actual decoding logic after $this->speakingUri is set.
	 *
	 * @return void
	 */
	protected function runDecoding() {
		$urlParts = $this->getUrlParts();

		// TODO Handle file name

		$cacheInfo = $this->getFromUrlCache($this->speakingUri);
		if (!is_array($cacheInfo)) {
			$cacheInfo = $this->doDecoding($urlParts['path']);
		}
		$this->setRequestVariables($cacheInfo);

		// If it is still not there (could have been added by other process!), than update
		if (!$this->getFromUrlCache($this->speakingUri)) {
			$this->putToUrlCache($cacheInfo);
		}
	}

	/**
	 * Searches pages for the match to the segment
	 *
	 * @param int $currentPid
	 * @param string $segment
	 * @return int
	 */
	protected function searchPages($currentPid, $segment) {
		$pagesEnableFields = $this->pageRepository->enableFields('pages');
		$pages = $this->databaseConnection->exec_SELECTgetRows('*', 'pages', 'pid=' . (int)$currentPid . ' AND doktype IN (1,2,4)' . $pagesEnableFields);
		foreach ($pages as $page) {
			foreach (self::$pageTitleFields as $field) {
				if ($this->utility->convertToSafeString($page[$field]) == $segment) {
					return (int)$page['uid'];
				}
			}
		}

		return 0;
	}

	/**
	 * Fetches the entry from the RealURL path cache. This would start stripping
	 * segments if the entry is not found until none is left. Effectively it is
	 * a search for the largest caching path for those segments.
	 *
	 * @param array $pathSegments
	 * @return int
	 */
	protected function searchPathInCache(array &$pathSegments) {
		$result = 0;
		$removedSegments = array();

		while ($result === 0 && count($pathSegments) > 0) {
			$path = implode('/', $pathSegments);
			$row = $this->databaseConnection->exec_SELECTgetSingleRow('*', 'tx_realurl_pathcache',
				'rootpage_id=' . (int)$this->rootPageId . ' AND pagepath=' . $this->databaseConnection->fullQuoteStr($path, 'tx_realurl_pathcache'),
				'', 'expire'
			);
			if (is_array($row)) {
				$result = $row['page_id'];
			}
			else {
				array_unshift($removedSegments, array_pop($pathSegments));
			}
		}
		$pathSegments = $removedSegments;

		return $result;
	}

	/**
	 * Sets current language from the query string variable ('L').
	 *
	 * @return void
	 */
	protected function setLanguageFromQueryString() {
		$this->sysLanguageUid = (int)GeneralUtility::_GP('L');
	}

	/**
	 * Sets variables after the decoding.
	 *
	 * @param array $cacheInfo
	 */
	private function setRequestVariables(array $cacheInfo) {
		if ($cacheInfo['id']) {
			$_SERVER['QUERY_STRING'] = $this->createQueryString($cacheInfo['GET_VARS']);

			// Setting info in TSFE
			$this->caller->mergingWithGetVars($cacheInfo['GET_VARS']);
			$this->caller->id = $cacheInfo['id'];

			if ($this->mimeType) {
				header('Content-type: ' . $this->mimeType);
				$this->mimeType = null;
			}
		}
	}

	/**
	 * Obtains speaking URI from the site script.
	 *
	 * @return void
	 */
	protected function setSpeakingUriFromSiteScript() {
		$this->speakingUri = ltrim($this->siteScript, '/');

		// Call hooks
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['realurl']['decodeSpURL_preProc'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['realurl']['decodeSpURL_preProc'] as $userFunc) {
				$hookParams = array(
					'pObj' => $this,
					'params' => array(),
					'URL' => &$this->speakingUri,
				);
				GeneralUtility::callUserFunction($userFunc, $hookParams, $this);
			}
		}
	}

	/**
	 * Throws a 404 error with the corresponding message.
	 *
	 * @param string $errorMessage
	 * @return void
	 */
	protected function throw404($errorMessage) {
		// TODO Write to our own error log here
		$this->caller->pageNotFoundAndExit($errorMessage);
	}
}
