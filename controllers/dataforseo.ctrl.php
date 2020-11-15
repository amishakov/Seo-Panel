<?php

/***************************************************************************
 *   Copyright (C) 2009-2011 by Geo Varghese(www.seopanel.in)  	           *
 *   sendtogeo@gmail.com   												   *
 *                                                                         *
 *   This program is free software; you can redistribute it and/or modify  *
 *   it under the terms of the GNU General Public License as published by  *
 *   the Free Software Foundation; either version 2 of the License, or     *
 *   (at your option) any later version.                                   *
 *                                                                         *
 *   This program is distributed in the hope that it will be useful,       *
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of        *
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the         *
 *   GNU General Public License for more details.                          *
 *                                                                         *
 *   You should have received a copy of the GNU General Public License     *
 *   along with this program; if not, write to the                         *
 *   Free Software Foundation, Inc.,                                       *
 *   59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.             *
 ***************************************************************************/

include_once(SP_LIBPATH."/dataforseo/RestClient.php");

/**
 * Class defines all details about managing DataForSEO API
 */
class DataForSEOController extends Controller {
    
    var $restClient;
//     var $apiUrl = 'https://api.dataforseo.com/';
    var $apiUrl = 'https://sandbox.dataforseo.com/';
    
    function __construct() {
        parent::__construct();
        $this->restClient = new RestClient($this->apiUrl, null, SP_DFS_API_LOGIN, SP_DFS_API_PASSWORD);
    }
    
    function __checkAPIConnection($apiLogin, $apiPassword) {
        
        $connResult = [
            'status' => false, 
            'message' => $_SESSION['text']['common']['Internal error occured'], 
            'balance' => 0,
        ];
        
        if (!empty($apiLogin) && !empty($apiPassword)) {
            $this->restClient = new RestClient($this->apiUrl, null, $apiLogin, $apiPassword);
            $connResult = $this->getUserAccountDetails();
            
            if ($connResult['status']) {
                $result = $connResult['data'];
                if ($result['status_code'] == 20000) {
                    foreach ($result['tasks'] as $taskInfo) {
                        if ($taskInfo['status_code'] == 20000 && $taskInfo['data']['function'] == 'user_data') {
                            $balance = isset($taskInfo['result'][0]['money']['balance']) ? $taskInfo['result'][0]['money']['balance'] : 0;
                            $connResult['balance'] = $balance;
                            $this->updateAPIBalance($balance);
                            break;
                        }
                    }
                } else {
                    $connResult['status'] = false;
                    $connResult['message'] = $result['status_message'];
                }
            }            
        }
        
        return $connResult;
    }
    
    function getUserAccountDetails() {
        $res = ['status' => false, 'message' => $_SESSION['text']['common']['Internal error occured']];
        
        try {
            $result = $this->restClient->get('/v3/appendix/user_data');
            $res['status'] = true;
            $res['data'] = $result;
        } catch (RestClientException $e) {
            $msg = "HTTP code: {$e->getHttpCode()}\n";
            $msg .= "Error code: {$e->getCode()}\n";
            $msg .= "Message: {$e->getMessage()}\n";
            $res['message'] = $msg;
        }
            
        return $res;
    }
    
    function updateAPIBalance($balance) {
        $res = $this->dbHelper->updateRow('settings', ['set_val' => $balance], "set_name='SP_DFS_BALANCE'");
        return $res;
    }
    
    public static function getSERPDomainCategory($seachEngine) {
        $seDomianCat = 'google';
        if (stristr($seachEngine, 'yahoo.')) {
            $seDomianCat = 'yahoo';
        } elseif (stristr($seachEngine, 'bing.')) {
            $seDomianCat = 'bing';
        } elseif (stristr($seachEngine, 'yandex.')) {
            $seDomianCat = 'yandex';            
        } elseif (stristr($seachEngine, 'baidu.')) {
            $seDomianCat = 'baidu';            
        }
        
        return $seDomianCat;
    }    
    
    function doSERPAPICall($keywordInfo, $seachEngine, $cat='organic', $subCat='live', $dataType='regular') {
        $connResult = [
            'status' => false,
            'message' => $_SESSION['text']['common']['Internal error occured'],
            'data' => [],
        ];
        
        $seDomianCat = DataForSEOController::getSERPDomainCategory($seachEngine);        
        $searchInfo = array(
            "se_domain" => $seachEngine,
            "keyword" => mb_convert_encoding($keywordInfo['name'], "UTF-8"),
        );
        
        if (!empty($keywordInfo['lang_code'])) {
            $searchInfo['language_code'] = $keywordInfo['lang_code'];
        }
        
        if (!empty($keywordInfo['location_name'])) {
            $searchInfo['location_name'] = $keywordInfo['location_name'];
        }        
        
        try {
            $result = $this->restClient->post("/v3/serp/$seDomianCat/$cat/$subCat/$dataType", [$searchInfo]);
            $connResult['status'] = true;
            $connResult['message'] = "Success";
        } catch (RestClientException $e) {
            $msg = "HTTP code: {$e->getHttpCode()}\n";
            $msg .= "Error code: {$e->getCode()}\n";
            $msg .= "Message: {$e->getMessage()}\n";
            $connResult['message'] = $msg;
        }
        
        if ($connResult['status']) {
            if ($result['status_code'] == 20000) {
                foreach ($result['tasks'] as $taskInfo) {
                    if ($taskInfo['status_code'] == 20000 && isset($taskInfo['result'][0])) {
                        $connResult['data'] = $taskInfo['result'][0];
                        break;
                    }
                }
            } else {
                $connResult['status'] = false;
                $connResult['message'] = $result['status_message'];
            }
        }
        
        return $connResult;
    }
    
    function __getSERPResults($keywordInfo, $showAll = false, $seId = false, $cron = false) {
        
        $crawlResult = array();
        $seFound = false;
        $websiteUrl = formatUrl($keywordInfo['url'], false);
        if(empty($websiteUrl)) return $crawlResult;
        if(empty($keywordInfo['name'])) return $crawlResult;
        
        $time = mktime(0, 0, 0, date('m'), date('d'), date('Y'));
        $seController = New SearchEngineController();
        $seList = $seController->__getAllCrawlFormatedSearchEngines();        
        $websiteOtherUrl = SettingsController::getWebsiteOtherUrl($websiteUrl);
        
        $countryCtrl = new CountryController();
        $countryList = $countryCtrl->__getAllCountryAsList();
        
        // set country name as location
        if (!empty($keywordInfo['country_code'])) {
            $keywordInfo['location_name'] = $countryList[$keywordInfo['country_code']];
        }
        
        $keySeList = explode(':', $keywordInfo['searchengines']);
        foreach($keySeList as $seInfoId) {
            
            // function to execute only passed search engine
            if(!empty($seId) && ($seInfoId != $seId)) continue;
            
            // if search engine not found continue
            if (empty($seList[$seInfoId])) continue;
            
            // call serp api to get the results
            $seFound = true;
            $seachEngine = formatUrl($seList[$seInfoId]['domain']);
            $result = $this->doSERPAPICall($keywordInfo, $seachEngine);
            
            // check crawl status
            if(!empty($result['status'])) {
                
                // to update cron that report executed for akeyword on a search engine
                if ($cron) {
                    $this->saveCronTrackInfo($keywordInfo['id'], $seInfoId, $time);
                }
                
                // verify results array having search results
                if (!empty($result['data']['items'])) {
                    $crawlResult[$seInfoId]['matched'] = array();
                    
                    // loop through the results
                    foreach ($result['data']['items'] as $itemInfo) {
                        $url = $itemInfo['url'];
                        if (
                            $showAll || (
                            stristr($url, "http://" . $websiteUrl) || stristr($url, "https://" . $websiteUrl) ||
                            stristr($url, "http://" . $websiteOtherUrl) || stristr($url, "https://" . $websiteOtherUrl))
                        ) { 
                            $matchInfo = [];
                            if (
                                $showAll && (
                                stristr($url, "http://" . $websiteUrl) || stristr($url, "https://" . $websiteUrl) ||
                                stristr($url, "http://" . $websiteOtherUrl) || stristr($url, "https://" . $websiteOtherUrl))
                            ) {
                                $matchInfo['found'] = 1;
                            } else {
                                $matchInfo['found'] = 0;
                            }
                                
                            $matchInfo['url'] = $url;
                            $matchInfo['title'] = $itemInfo['title'];
                            $matchInfo['description'] = $itemInfo['description'];
                            $matchInfo['rank'] = $itemInfo['rank_group'];
                            $crawlResult[$seInfoId]['matched'][] = $matchInfo;
                        }
                    }
                }
            } else {
                if (SP_DEBUG) {
                    echo "<p class='note' style='text-align:left;'>
                            Error occured while crawling  keyword {$keywordInfo['name']} from $seachEngine - ".formatErrorMsg($result['message']."<br>\n")."</p>";
                }
            }
            
            $crawlResult[$seInfoId]['seFound'] = $seFound;
            $crawlResult[$seInfoId]['status'] = $result['status'];
            
            // create crawl log
            $crawlLogCtrl = new CrawlLogController();
            $crawlInfo = [];
            $crawlInfo['crawl_type'] = 'keyword';
            $crawlInfo['crawl_status'] = $result['status'] ? 0 : 1;
            $crawlInfo['ref_id'] = empty($keywordInfo['id']) ? $keywordInfo['name'] : $keywordInfo['id'];
            $crawlInfo['subject'] = $seInfoId;
            $crawlInfo['crawl_referer'] = "Dataforseo";
            $crawlInfo['log_message'] = addslashes($result['message']);
            $crawlInfo['crawl_link'] = !empty($result['data']['check_url']) ? $result['data']['check_url'] : "";
            $crawlLogCtrl->createCrawlLog($crawlInfo);
        }
        
        return  $crawlResult;        
    }
    
}
?>