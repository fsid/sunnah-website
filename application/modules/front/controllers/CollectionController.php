<?php

namespace app\modules\front\controllers;

use app\controllers\SController;
use yii\web\HttpException;
use Yii;
use app\modules\front\models\EnglishHadith;

class CollectionController extends SController
{
	protected $_collection;
	protected $_book;
	protected $_entries;
	protected $_chapters;
	protected $_collections;
	protected $_books;
	protected $_collectionName;
	protected $_ourBookID;
	protected $_otherlangs;

    public function behaviors() {
        return [
            [
                   'class' => 'yii\filters\PageCache',
                   'except' => ['ramadandata'],
                   'duration' => Yii::$app->params['cacheTTL'],
                   'variations' => [ 
                       Yii::$app->request->get('id'), 
                       Yii::$app->request->get('collectionName'),
                       Yii::$app->request->get('ourBookID'),
                       Yii::$app->request->get('urn'),
                       Yii::$app->request->get('hadithNumbers'),
                       Yii::$app->request->get('lang'),
                       Yii::$app->request->get('_escaped_fragment_'),
                   ],

            ],
        ];
	}

    public function actionAjaxHadith($collectionName, $ourBookID, $lang) {
        $this->_book = $this->util->getBook($collectionName, $ourBookID);
		if ($this->_book) {
			$this->_entries = $this->_book->fetchLangHadith($lang);
			echo json_encode($this->_entries);
		}
	}
	
	public function actionIndex($collectionName)
	{
		$this->view->params['_pageType'] = "collection";
		$this->_collection = $this->util->getCollection($collectionName);
        if ($this->_collection) {
        	$this->_entries = $this->util->getBook($collectionName);
        }
        if (is_null($this->_collection) || count($this->_entries) == 0) {
            $errorMsg = "There is no such collection on our website. Click <a href=\"/\">here</a> to go to the home page.";
        	return $this->render('index', ['entries' => $this->_entries, 'errorMsg' => $errorMsg]);
        }

        $this->pathCrumbs($this->_collection->englishTitle, "/$collectionName");
		if (strlen($this->_collection->shortintro) > 0) $this->view->params['_ogDesc'] = $this->_collection->shortintro;
        return $this->render('index', [
                                        'entries' => $this->_entries, 
                                        'collection' => $this->_collection,
                                        'collectionName' => $collectionName,
                                      ]);
    }
    
    public function actionAbout($collectionName, $splpage = NULL) {
		$this->_collection = $this->util->getCollection($collectionName);
        $this->view->params['_pageType'] = "about";
        $this->pathCrumbs("About", "");
        $this->pathCrumbs($this->_collection->englishTitle, "/$collectionName");
		$this->_viewVars = new \StdClass();
        $this->_viewVars->aboutInfo = $this->_collection->about;
        if (!is_null($splpage)) $this->_viewVars->splpage = $splpage;

        $this->view->params['_viewVars'] = $this->_viewVars;
        return $this->render('about', [
                                        'collection' => $this->_collection,
                                        'collectionName' => $collectionName,
                                        
                                      ]);
    }

    public function actionDispbook($collectionName, $ourBookID, $hadithNumbers = NULL, $_escaped_fragment_ = "default") {
        if (!(is_null($hadithNumbers))) $hadithRange = addslashes($hadithNumbers);
        else $hadithRange = NULL;
		$this->_collection = $this->util->getCollection($collectionName);
        if (is_null($this->_collection)) {
            $this->view->params['_pageType'] = "book";
			$errorMsg = "There is no such collection on our website. Please use the menu above to navigate the website.";
        	return $this->render('dispbook', ['errorMsg' => $errorMsg]);
        }
        
		$this->view->params['collection'] = $this->_collection;
        $this->_book = $this->util->getBook($collectionName, $ourBookID);
        if (!is_null($this->_book)) $expectedHadithCount = $this->_book->totalNumber;
        $this->view->params['book'] = $this->_book;
        if ($this->_book) $this->_entries = $this->_book->fetchHadith($hadithRange);
        $pairs = $this->_entries[2];
        if (($this->_book) and ($this->_book->status == 4) and is_array($pairs) and ($expectedHadithCount != count($pairs)) and is_null($hadithRange)) 
            Yii::warning("hadith count should be ".$expectedHadithCount." and pairs length is ".count($pairs));
		$this->view->params['lastUpdated'] = $this->_entries[3];

        if (is_null($hadithRange)) {
			$this->view->params['_pageType'] = "book";
		}
        else {
            $this->view->params['_pageType'] = "hadith";
            $this->pathCrumbs('Hadith', "");
        }

        $viewVars = [
            'englishEntries' => $this->_entries[0],
            'arabicEntries' => $this->_entries[1],
            'pairs' => $this->_entries[2],
            'ourBookID' => $ourBookID,
            'collection' => $this->_collection,
            'book' => $this->_book,
			'expectedHadithCount' => $expectedHadithCount,
        ];
        
		if (isset($this->_entries[0][$pairs[0][0]])) $this->view->params['_ogDesc'] = substr(strip_tags($this->_entries[0][$pairs[0][0]]->hadithText), 0, 300);

		if (strcmp($_escaped_fragment_, "default") != 0) {
			//if ($this->_book->indonesianBookID > 0) $this->_otherlangs['indonesian'] = $this->_book->fetchLangHadith("indonesian");
            if ($this->_book->urduBookID > 0) {
                $this->_otherlangs['urdu'] = $this->_book->fetchLangHadith("urdu");
                $viewVars['otherlangs'] = $this->_otherlangs;
            }
            
            if (!is_null($this->_otherlangs)) {
				if (count($this->_otherlangs) > 0) {
                    $viewVars['ajaxCrawler'] = true; 
				}
			}
		}

        if (!isset($this->_entries) || count($this->_entries) == 0) {
			// Special case for 0-hadith Hisn al-Muslim introduction book which is valid
			if (strcmp($collectionName, "hisn") != 0) {
            	$errorMsg = "You have entered an incorrect URL. Please use the menu above to navigate the website.";
        		return $this->render('dispbook', ['errorMsg' => $errorMsg]);
			}
        }

		if ($this->_book->status > 3) {
			$this->_chapters = array();
			$retval = $this->util->getChapterDataForBook($collectionName, $ourBookID);
            foreach ($retval as $chapter) $this->_chapters[$chapter->babID] = $chapter;
            $viewVars['chapters'] = $this->_chapters;
		}

        if ((strlen($this->_book->englishBookName) > 0) and (strcmp($this->_collection->hasbooks, "yes") == 0)) {
			if (intval($ourBookID) == -1) $lastlink = "introduction";
			elseif (intval($ourBookID) == -35) $lastlink = "35b";
			elseif (intval($ourBookID) == -8) $lastlink = "8b";
			else $lastlink = $ourBookID;
			$bookTitlePrefix = "";
			if (strcmp(substr($this->_book->englishBookName, 0, 4), "Book") != 0 && strcmp(substr($this->_book->englishBookName, 0, 7), "Chapter") != 0 && strcmp(substr($this->_book->englishBookName, 0, 4), "The ") != 0)
				$bookTitlePrefix = "Book of ";
            $this->pathCrumbs($bookTitlePrefix.$this->_book->englishBookName, "/".$collectionName."/".$lastlink);
        }
		elseif ($ourBookID == -1) {
			// The case where the collection doesn't technically have books but there is an introduction pseudobook
			$lastlink = "introduction";
			$this->pathCrumbs($this->_book->englishBookName, "/".$collectionName."/".$lastlink);
		}
        $this->pathCrumbs($this->_collection->englishTitle, "/$collectionName");
        return $this->render('dispbook', $viewVars);  
	}

	public function actionTce() {
		$aURNs = array(100010, 100020, 100030, 100040, 100050, 100060, 100070, 100080, 100610, 109660, 109670, 109720, 109740, 129070, 129100, 171070, 171150, 132790, 132800, 133900, 138910, 138920, 138930, 138940, 138950, 138960, 183040, 183050, 183060, 143020, 144840, 144850, 151341, 174620, 174640, 174660, 174700, 174710, 156010, 160700, 160770, 161260, 163480, 163810, 164280, 176060, 177450);
		$this->_viewVars->pageTitle = "The Collector's Edition";
        $this->pathCrumbs($this->_viewVars->pageTitle, "");
		$this->customSelect($aURNs, true, true);
	}

	public function actionSocialmedia() {
		$aURNs = array(158030, 
			155850, 
			724820, 
			100130, 
			368971, 
			948360, 
			721230, 
			725620, 
			153320,
			173800,
			367980,
			721100,
			721130,
			/* Hadith Musnad Ahmad */
			302050,
			/* 1343270 unverified */
			174310,
			720550,
			153400,
			149460,
			155870,
			367820,
			2304230,
			303030,
			/* 1342400 unverified */
			949930,
			162970,
			160180,
			161590,
			/* 735080 unverified */
			153940,
			728370,
			948030,
			1341710,
			/* 1333750 unverified */
			123240,
			144010,
			155191,
			725881,
			/* 726910 unverified */

			350410,
			172440,
			/* 1302380 unverified */

			/* Hadith Musnad Ahmad */
			380090
 		);
		$this->_viewVars->pageTitle = "40 Hadith on Social Media";
        $this->_viewVars->showChapters = false;
		$this->pathCrumbs($this->_viewVars->pageTitle, "");
		$this->customSelect($aURNs, false, false);
	}

	public function actionRamadan() {
		$aURNs = $this->util->getRamadanURNs();
		$this->view->params['pageTitle'] = "Ramadan Selection";
        $this->pathCrumbs($this->view->params['pageTitle'], "");
		return $this->customSelect($aURNs, false, false);
	}

	public function actionRamadandata() {
		$this->layout = false;
        $aURNs = $this->util->getRamadanURNs();
		shuffle($aURNs);
        $retval = $this->util->customSelect($aURNs);
        $collections = $retval[0];
        $books = $retval[1];
        $chapters = $retval[2];
        $entries = $retval[3];
	    $englishEntries = $entries[0];
	    $arabicEntries = $entries[1];
    	$pairs = $entries[2];

		$s = "";
		foreach ($pairs as $pair) {
			$s .= "\n<li><div class=carousel_item>\n";
			$englishEntry = $englishEntries[$pair[0]];
			$arabicEntry = $arabicEntries[$pair[1]];

			$arabicText = $arabicEntry->hadithText;
			$englishText = $englishEntry->hadithText;
			$truncation = false;

			if (strlen($arabicText) <= 530) $arabicSnippet = $arabicText;
            else {
            	$pos = strpos($arabicText, ' ', 530);
                if ($pos === FALSE) $arabicSnippet = $arabicText;
                else {
					$arabicSnippet = substr($arabicText, 0, $pos)." &hellip;";
					$truncation = true;
				}
            }

			if (strlen($englishText) <= 300) $englishSnippet = $englishText;
            else {
            	$pos = strpos($englishText, ' ', 300);
                if ($pos === FALSE) $englishSnippet = $englishText;
                else {
					$englishSnippet = substr($englishText, 0, $pos)." &hellip;";
					$truncation = true;
				}
            }

			$s .= "<div class=arabic>".$arabicSnippet."</div>";

			$englishText = $englishSnippet;
			$s .= "<div class=\"english_hadith_full\" style=\"padding-left: 0;\">";
            if (strpos($englishText, ":") === FALSE) {
                $s .= "<div class=text_details>\n
                     ".$englishText."</div><br />\n";
            }
            else {
                $s .= "<div class=hadith_narrated>".strstr($englishText, ":", true).":</div>";
                $s .= "<div class=text_details>
                     ".substr(strstr($englishText, ":", false), 1)."</div>\n";
            }
            $s .= "<div class=clear></div></div>";

			//$s .= "<div class=text_details style=\"margin-top: 10px;\">".$englishSnippet."</div>";

			if ($truncation) {
				$permalink = "/".$arabicEntry->collection."/".$books[$arabicEntry->arabicURN]->ourBookID."/".$arabicEntry->ourHadithNumber;
				$s .= "<div style=\"text-align: right; width: 100%;\"><a href=\"$permalink\">Full hadith &hellip;</a></div>";
			}

			$s .= "<div class=hadith_reference style=\"padding: 5px 0 0 0; font-size: 12px;\">";
			$s .= $collections[$arabicEntry->collection]['englishTitle'];
			$s .= " ".$arabicEntry->hadithNumber;
			$s .= "</div>";

			$s .= "\n</div></li>\n";
		}

		return $s;
	}

    public function customSelect($aURNs, $showBookNames, $showChapterNumbers) {
		$retval = $this->util->customSelect($aURNs);
		$this->_collections = $retval[0];
		$this->_books = $retval[1];
		$this->_chapters = $retval[2];
		$this->_entries = $retval[3];

        $viewVars = [
            'collections' => $this->_collections,
            'englishEntries' => $this->_entries[0],
            'arabicEntries' => $this->_entries[1],
            'pairs' => $this->_entries[2],
            'chapters' => $this->_chapters,
            'showBookNames' => $showBookNames,
            'showChapterNumbers' => $showChapterNumbers,
        ];

        $this->view->params['_pageType'] = "book";

        return $this->render('tce', $viewVars);
	}
	
	public function actionUrn($urn) {
        $englishHadith = NULL; $arabicHadith = NULL;
        $viewVars = array();

        if (is_numeric($urn)) {
            $lang = "english";
            $englishHadith = $this->util->getHadith($urn, "english");
            if (is_null($englishHadith) || $englishHadith === false) {
                $lang = "arabic";
                $arabicHadith = $this->util->getHadith($urn, $lang);
                if ($arabicHadith) $englishHadith = $this->util->getHadith($arabicHadith->matchingEnglishURN, "english");
            }
            else $arabicHadith = $this->util->getHadith($englishHadith->matchingArabicURN, "arabic");

			if (is_null($englishHadith) && is_null($arabicHadith)) {
				return Yii::$app->runAction('front/index/index');
			}

            if (strcmp($lang, "english") == 0) {
            	$this->_collectionName = $englishHadith->collection;
            	$this->_book = $this->util->getBookByLanguageID($this->_collectionName, $englishHadith->bookID, "english");
                $this->view->params['book'] = $this->_book;
                $viewVars['collectionName'] = $this->_collectionName;
                $viewVars['book'] = $this->_book;
            }
            else if (!(is_null($arabicHadith))) {
            	$this->_collectionName = $arabicHadith->collection;
        		$this->_book = $this->util->getBookByLanguageID($this->_collectionName, $arabicHadith->bookID, "arabic");
                $this->view->params['book'] = $this->_book;
                $viewVars['collectionName'] = $this->_collectionName;
                $viewVars['book'] = $this->_book;
        	}
            
        	$this->_collection = $this->util->getCollection($this->_collectionName);
            $this->view->params['collection'] = $this->_collection;
            $viewVars['collection'] = $this->_collection;
        }

		//$this->_viewVars = new StdClass();
        $viewVars['englishEntry'] = $englishHadith;
        $viewVars['arabicEntry'] = $arabicHadith;
        $this->view->params['_pageType'] = "hadith";
        $this->pathCrumbs('Hadith', "");
        if (strlen($this->_book->englishBookName) > 0) {
            $this->pathCrumbs($this->_book->englishBookName." - <span class=arabic_text>".$this->_book->arabicBookName.'</span>', "/".$this->_collectionName."/".$this->_book->ourBookID);
        }
        $this->pathCrumbs($this->_collection->englishTitle, "/$this->_collectionName");
        echo $this->render('urn', $viewVars);
	}
}