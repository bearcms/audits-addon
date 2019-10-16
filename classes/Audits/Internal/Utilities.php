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
        $robotsURL = $data['url'] . 'robots.txt';
        $result = self::makeRequest($robotsURL);
        if ($result['status'] === 200) {
            $robotsLines = explode("\n", $result['content']);
            $sitemapURL = null;
            $data['allowSearchEngines'] = true;
            foreach ($robotsLines as $robotsLine) {
                $robotsLine = strtolower(trim($robotsLine));
                if (strlen($robotsLine) === 0) {
                    continue;
                }
                if (strpos($robotsLine, 'Disallow:') === 0) {
                    $data['allowSearchEngines'] = (int) ($robotsLine === 'Disallow:');
                } elseif (strpos($robotsLine, 'sitemap:') === 0) {
                    $sitemapURL = trim(substr($robotsLine, 8));
                }
            }
            if (strlen($sitemapURL) > 0) {
                $result = self::makeRequest($sitemapURL);
                if ($result['status'] === 200) {
                    $maxPagesCount = $data['maxPagesCount'];
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
                    } catch (Exception $e) {
                        $data['errors'] = 'Error finding URLs in ' . $sitemapURL;
                    }
                } else {
                    $data['errors'] = 'There is a problem with ' . $sitemapURL . ' (status:' . $result['status'] . ')';
                }
            } else {
                $data['errors'] = 'Cannot find sitemap URL in ' . $robotsURL;
            }
        } else {
            $data['errors'] = 'There is a problem with ' . $robotsURL . ' (status:' . $result['status'] . ')';
        }

        $urls = array_unique($urls);

        $data['pages'] = [];
        $tasksData = [];
        foreach ($urls as $url) {
            $pageID = md5($url);
            $data['pages'][$pageID] = [
                'url' => $url
            ];
            $addTask = true;
            if ($maxPagesCount !== null) {
                if (sizeof($data['pages']) > $maxPagesCount) {
                    $data['pages'][$pageID]['status'] = -1;
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
        if (isset($data['pages'][$pageID])) {
            $pageData = $data['pages'][$pageID];
            $result = self::makeRequest($pageData['url']);
            $pageData['status'] = $result['status'];
            $pageData['dateChecked'] = date('c');
            self::setURLStatusInCache($id, $pageData['url'], $result['status'], $pageData['dateChecked']);
            if ($result['status'] === 200) {
                $pageData['title'] = null;
                $pageData['description'] = null;
                $pageData['links'] = [];
                $dom = new HTML5DOMDocument();
                $dom->loadHTML($result['content'], HTML5DOMDocument::ALLOW_DUPLICATE_IDS);
                $headElement = $dom->querySelector('head');
                if ($headElement !== null) {
                    $titleElement = $headElement->querySelector('title');
                    if ($titleElement !== null) {
                        $pageData['title'] = $titleElement->innerHTML;
                    }
                    $metaDescriptionElement = $headElement->querySelector('meta[name="description"]');
                    if ($metaDescriptionElement !== null) {
                        $pageData['description'] = $metaDescriptionElement->getAttribute('content');
                    }
                }
                $tasksData = [];
                $links = $dom->querySelectorAll('a');
                $counter = 0;
                foreach ($links as $link) {
                    $linkLocation = trim($link->getAttribute('href'));
                    if (strlen($linkLocation) > 0 && strpos($linkLocation, 'javascript:') !== 0 && strpos($linkLocation, 'mailto:') !== 0) {
                        $counter++;
                        $linkID = md5($linkLocation . '-' . $counter);
                        $pageData['links'][$linkID] = [
                            'url' => $linkLocation,
                            'status' => null
                        ];
                        list($linkStatus, $linkDate) = self::getURLStatusFromCache($id, $linkLocation);
                        if ($linkStatus !== null) {
                            $pageData['links'][$linkID]['status'] = $linkStatus;
                            $pageData['links'][$linkID]['dateChecked'] = $linkDate;
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
            $data['pages'][$pageID] = $pageData;
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
        if (isset($data['pages'][$pageID], $data['pages'][$pageID]['links'][$linkID])) {
            $linkData = $data['pages'][$pageID]['links'][$linkID];
            list($status, $date) = self::getURLStatusFromCache($id, $linkData['url']);
            if ($status === null) {
                $date = date('c');
                $result = self::makeRequest($linkData['url'], false);
                $status = $result['status'];
                self::setURLStatusInCache($id, $linkData['url'], $status, $date);
            }
            $linkData['status'] = $status;
            $linkData['dateChecked'] = $date;
            $data['pages'][$pageID]['links'][$linkID] = $linkData;
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
        $app = App::get();
        $value = $app->data->getValue(self::getDataKey($id));
        if ($value !== null) {
            return json_decode($value, true);
        }
        return null;
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
        //    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        //    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
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
}
