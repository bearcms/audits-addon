<?php

/*
 * Audits addon for Bear CMS
 * https://github.com/bearcms/audits-addon
 * Copyright (c) Amplilabs Ltd.
 * Free to use under the MIT license.
 */

namespace BearCMS\Audits\Internal;

use BearFramework\App;
use DOMDocument;
use IvoPetkov\HTML5DOMDocument;

/**
 *
 */
class Utilities
{

    /**
     * @var array
     */
    static $cache = [];

    /**
     * 
     * @param string $id
     * @return void
     */
    static function initializeAudit(string $id): void
    {
        $app = App::get();
        $data = self::getData($id);
        if ($data === null) {
            return;
        }

        $urls = [];
        $robotsURL = $data['u'] . 'robots.txt';
        $result = self::makeRequest($robotsURL);
        if ($result['status'] === 200) {
            $robotsLines = explode("\n", $result['content']);
            $sitemapURL = null;
            $data['a'] = true;
            foreach ($robotsLines as $robotsLine) {
                $robotsLine = strtolower(trim($robotsLine));
                if (strlen($robotsLine) === 0) {
                    continue;
                }
                if (strpos($robotsLine, 'disallow:') === 0) {
                    $data['a'] = (int) ($robotsLine === 'disallow:');
                } elseif (strpos($robotsLine, 'sitemap:') === 0) {
                    $sitemapURL = trim(substr($robotsLine, 8));
                }
            }
            if (strlen($sitemapURL) > 0) {
                $result = self::makeRequest($sitemapURL);
                if ($result['status'] === 200) {
                    $maxPagesCount = $data['m'];
                    $dom = new DOMDocument();
                    try {
                        $dom->loadXML($result['content']);
                        $elements = $dom->getElementsByTagName('url');
                        foreach ($elements as $element) {
                            $locationElements = $element->getElementsByTagName('loc');
                            if ($locationElements->length === 1) {
                                $urls[] = $locationElements->item(0)->nodeValue;
                            }
                        }
                    } catch (\Exception $e) {
                        $data['e'] = 'Error finding URLs in ' . $sitemapURL;
                    }
                } else {
                    $data['e'] = 'There is a problem with ' . $sitemapURL . ' (status:' . $result['status'] . ')';
                }
            } else {
                $data['e'] = 'Cannot find sitemap URL in ' . $robotsURL;
            }
        } else {
            $data['e'] = 'There is a problem with ' . $robotsURL . ' (status:' . $result['status'] . ')';
        }

        $urls = array_unique($urls);

        $data['p'] = [];
        $tasksData = [];
        foreach ($urls as $url) {
            $pageID = md5($url);
            $data['p'][$pageID] = [
                'u' => self::getShortURL($data['u'], $url)
            ];
            $addTask = true;
            if ($maxPagesCount !== null) {
                if (sizeof($data['p']) > $maxPagesCount) {
                    $data['p'][$pageID]['s'] = -1;
                    $addTask = false;
                }
            }
            if ($addTask) {
                $tasksData[] = [
                    'definitionID' => 'bearcms-audits-check-page',
                    'data' => ['id' => $id, 'pageID' => $pageID]
                ];
            }
        }
        self::setData($id, $data);
        if (!empty($tasksData)) {
            $app->tasks->addMultiple($tasksData);
        }
    }

    /**
     * 
     * @param string $id
     * @param string $pageID
     * @return void
     */
    static function checkPage(string $id, string $pageID): void
    {
        $app = App::get();
        $data = self::getData($id);
        if ($data === null) {
            return;
        }
        if (isset($data['p'][$pageID])) {
            $pageData = $data['p'][$pageID];
            $fullPageURL = self::getFullURL($data['u'], $pageData['u']);
            $result = self::makeRequest($fullPageURL);
            $pageData['s'] = $result['status'];
            $pageData['d'] = date('c');
            self::setURLStatusInCache($id, $fullPageURL, $result['status'], $pageData['d']);
            if ($result['status'] === 200) {
                $pageData['t'] = null;
                $pageData['e'] = null;
                $pageData['k'] = null;
                $pageData['l'] = [];
                $pageData['c'] = null;
                $dom = new HTML5DOMDocument();
                $dom->loadHTML($result['content'], HTML5DOMDocument::ALLOW_DUPLICATE_IDS);
                $headElement = $dom->querySelector('head');
                if ($headElement !== null) {
                    $titleElement = $headElement->querySelector('title');
                    if ($titleElement !== null) {
                        $pageData['t'] = $titleElement->innerHTML;
                    }
                    $metaDescriptionElement = $headElement->querySelector('meta[name="description"]');
                    if ($metaDescriptionElement !== null) {
                        $pageData['e'] = $metaDescriptionElement->getAttribute('content');
                    }
                    $metaKeywordsElement = $headElement->querySelector('meta[name="keywords"]');
                    if ($metaKeywordsElement !== null) {
                        $pageData['k'] = $metaKeywordsElement->getAttribute('content');
                    }
                }
                $bodyElement = $dom->querySelector('body');
                if ($bodyElement !== null) {
                    $scriptElements = $bodyElement->querySelectorAll('script');
                    foreach ($scriptElements as $scriptElement) {
                        $scriptElement->parentNode->removeChild($scriptElement);
                    }
                    $content = $bodyElement->innerHTML;
                    $content = str_replace('&nbsp;', ' ', $content);
                    $content = strip_tags($content);
                    $content = htmlspecialchars_decode($content);
                    $pageData['c'] = $content;
                }
                $tasksData = [];
                $links = $dom->querySelectorAll('a');
                $counter = 0;
                foreach ($links as $link) {
                    $linkLocation = trim($link->getAttribute('href'));
                    if (strlen($linkLocation) > 0 && strpos($linkLocation, 'javascript:') !== 0 && strpos($linkLocation, 'mailto:') !== 0) {
                        $counter++;
                        $linkID = md5($linkLocation . '-' . $counter);
                        $pageData['l'][$linkID] = [
                            'u' => self::getShortURL($data['u'], $linkLocation),
                            's' => null
                        ];
                        list($linkStatus, $linkDate) = self::getURLStatusFromCache($id, $linkLocation);
                        if ($linkStatus !== null) {
                            $pageData['l'][$linkID]['s'] = $linkStatus;
                            $pageData['l'][$linkID]['d'] = $linkDate;
                        } else {
                            $tasksData[] = [
                                'definitionID' => 'bearcms-audits-check-page-link',
                                'data' => ['id' => $id, 'pageID' => $pageID, 'linkID' => $linkID]
                            ];
                        }
                    }
                }
                if (!empty($tasksData)) {
                    $app->tasks->addMultiple($tasksData);
                }
            }
            $data['p'][$pageID] = $pageData;
            self::setData($id, $data);
        }
    }

    /**
     * 
     * @param string $id
     * @param string $pageID
     * @param string $linkID
     * @return void
     */
    static function checkPageLink(string $id, string $pageID, string $linkID): void
    {
        $data = self::getData($id);
        if ($data === null) {
            return;
        }
        if (isset($data['p'][$pageID], $data['p'][$pageID]['l'][$linkID])) {
            $linkData = $data['p'][$pageID]['l'][$linkID];
            $fullPageLinkURL = self::getFullURL($data['u'], $linkData['u']);
            list($status, $date) = self::getURLStatusFromCache($id, $fullPageLinkURL);
            if ($status === null) {
                $date = date('c');
                $result = self::makeRequest($fullPageLinkURL, false);
                $status = $result['status'];
                self::setURLStatusInCache($id, $fullPageLinkURL, $status, $date);
            }
            $linkData['s'] = $status;
            $linkData['d'] = $date;
            $data['p'][$pageID]['l'][$linkID] = $linkData;
            self::setData($id, $data);
        }
    }

    /**
     * 
     * @param string $id
     * @return array|null
     */
    static function getData(string $id): ?array
    {
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }
        $app = App::get();
        $value = $app->data->getValue(self::getDataKey($id));
        if ($value !== null) {
            $result = json_decode($value, true);
            // Convert to v2 of the data format
            if (is_array($result) && isset($result['id'])) {
                $newResult = [];
                if (isset($result['id'])) {
                    $newResult['i'] = $result['id'];
                }
                if (isset($result['url'])) {
                    $newResult['u'] = $result['url'];
                }
                if (isset($result['dateRequested'])) {
                    $newResult['d'] = $result['dateRequested'];
                }
                if (isset($result['errors'])) {
                    $newResult['e'] = $result['errors'];
                }
                if (isset($result['pages'])) {
                    $pages = $result['pages'];
                    $newPages = [];
                    foreach ($pages as $pageID => $pageData) {
                        $newPages[$pageID] = [];
                        if (isset($pageData['status'])) {
                            $newPages[$pageID]['s'] = $pageData['status'];
                        }
                        if (isset($pageData['links'])) {
                            $links = $pageData['links'];
                            $newLinks = [];
                            foreach ($links as $linkID => $linkData) {
                                $newLinks[$linkID] = [];
                                if (isset($linkData['status'])) {
                                    $newLinks[$linkID]['s'] = $linkData['status'];
                                }
                                if (isset($linkData['url'])) {
                                    $newLinks[$linkID]['u'] = $linkData['url'];
                                }
                                if (isset($linkData['dateChecked'])) {
                                    $newLinks[$linkID]['d'] = $linkData['dateChecked'];
                                }
                            }
                            $newPages[$pageID]['l'] = $newLinks;
                        }
                        if (isset($pageData['url'])) {
                            $newPages[$pageID]['u'] = $pageData['url'];
                        }
                        if (isset($pageData['dateChecked'])) {
                            $newPages[$pageID]['d'] = $pageData['dateChecked'];
                        }
                        if (isset($pageData['title'])) {
                            $newPages[$pageID]['t'] = $pageData['title'];
                        }
                        if (isset($pageData['description'])) {
                            $newPages[$pageID]['e'] = $pageData['description'];
                        }
                        if (isset($pageData['keywords'])) {
                            $newPages[$pageID]['k'] = $pageData['keywords'];
                        }
                        if (isset($pageData['content'])) {
                            $newPages[$pageID]['c'] = $pageData['content'];
                        }
                    }
                    $newResult['p'] = $newPages;
                }
                if (isset($result['maxPagesCount'])) {
                    $newResult['m'] = $result['maxPagesCount'];
                }
                if (isset($result['allowSearchEngines'])) {
                    $newResult['a'] = $result['allowSearchEngines'];
                }
                $result = $newResult;
            }
        } else {
            $result = null;
        }
        self::$cache[$id] = $result;
        return $result;
    }

    /**
     * 
     * @param string $id
     * @param array $data
     * @return void
     */
    static function setData(string $id, array $data): void
    {
        $app = App::get();
        $app->data->setValue(self::getDataKey($id), json_encode($data));
        self::$cache[$id] = $data;
    }

    /**
     * 
     * @param string $id
     * @return void
     */
    static function deleteData(string $id): void
    {
        $app = App::get();
        $app->data->delete(self::getDataKey($id));
        if (isset(self::$cache[$id])) {
            unset(self::$cache[$id]);
        }
    }

    /**
     * 
     * @param string $id
     * @return string
     */
    static function getDataKey(string $id): string
    {
        return 'bearcms-audits/' . md5($id) . '.json';
    }

    /**
     * 
     * @param string $url
     * @param boolean $returnContent
     * @return array
     */
    static function makeRequest(string $url, bool $returnContent = true): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_ENCODING, "gzip");
        curl_setopt($ch, CURLOPT_USERAGENT, 'bearcms-audits-bot');
        if (!$returnContent) {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $content = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => (int) $httpCode, 'content' => ($returnContent ? (string) $content : null)];
    }

    /**
     * 
     * @param string $id
     * @param string $url
     * @param integer $status
     * @param string $date
     * @return void
     */
    static function setURLStatusInCache(string $id, string $url, int $status, string $date): void
    {
        $app = App::get();
        $app->cache->set($app->cache->make(self::getURLStatusCacheKey($id, $url), json_encode([(int) $status, $date])));
    }

    /**
     * 
     * @param string $id
     * @param string $url
     * @return array
     */
    static function getURLStatusFromCache(string $id, string $url): array
    {
        $app = App::get();
        $value = $app->cache->getValue(self::getURLStatusCacheKey($id, $url));
        if ($value === null) {
            return [null, null];
        }
        return json_decode($value, true);
    }

    /**
     * 
     * @param string $id
     * @param string $url
     * @return string
     */
    static function getURLStatusCacheKey(string $id, string $url): string
    {
        return 'bearcms-audits-' . md5($id) . '-' . md5($url);
    }

    /**
     * 
     * @param string $baseURL
     * @param string $fullURL
     * @return string
     */
    static function getShortURL(string $baseURL, string $fullURL): string
    {
        if (strpos($fullURL, $baseURL) === 0) {
            return '*' . substr($fullURL, strlen($baseURL));
        }
        return $fullURL;
    }

    /**
     *
     * @param string $baseURL
     * @param string $shortURL
     * @return string
     */
    static function getFullURL(string $baseURL, string $shortURL): string
    {
        if (substr($shortURL, 0, 1) === '*') {
            return $baseURL . substr($shortURL, 1);
        }
        return $shortURL;
    }
}
