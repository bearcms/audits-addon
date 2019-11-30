<?php

/*
 * Audits addon for Bear CMS
 * https://github.com/bearcms/audits-addon
 * Copyright (c) Amplilabs Ltd.
 * Free to use under the MIT license.
 */

namespace BearCMS;

use BearFramework\App;
use BearCMS\Audits\Internal\Utilities;

/**
 *
 */
class Audits
{
    /**
     * Returns a list containing the audits IDs
     *
     * @return \BearFramework\DataList
     */
    public function getList(): \BearFramework\DataList
    {
        return new \BearFramework\DataList(function () {
            $result = [];
            $app = App::get();
            $list = $app->data->getList()->filterBy('key', 'bearcms-audits/', 'startWith');
            foreach ($list as $item) {
                $data = json_decode($item->value, true);
                if (is_array($data)) {
                    $result[] = [
                        'id' => $data['id'],
                        'dateRequested' => $data['dateRequested'],
                    ];
                }
            }
            return $result;
        });
    }

    /**
     * Requests a new audits. The audit ID is returned.
     *
     * @param integer $maxPagesCount The maximum number of pages to analyze
     * @return string The ID of the audit requested.
     */
    public function request(int $maxPagesCount = null): string
    {
        $id = md5(uniqid());
        $app = App::get();
        $data = [];
        $data['id'] = $id;
        $data['url'] = $app->urls->get('/');
        $data['pages'] = null;
        $data['allowSearchEngines'] = null;
        $data['errors'] = [];
        $data['dateRequested'] = date('c');
        $data['maxPagesCount'] = $maxPagesCount;
        Utilities::setData($id, $data);
        $app->tasks->add('bearcms-audits-initialize', $id);
        return $id;
    }

    /**
     * Deletes an audit.
     *
     * @param string $id The ID of the audit to delete.
     * @return void
     */
    public function delete(string $id): void
    {
        Utilities::deleteData($id);
    }

    /**
     * Returns the status of an audit.
     *
     * @param string $id The audit ID.
     * @return array An array in the following format: ['status'=>...]
     */
    public function getStatus(string $id): array
    {
        $result = [];
        $result['id'] = $id;
        $data = Utilities::getData($id);
        if ($data === null) {
            $result['status'] = 'NOTFOUND';
        } else {
            $result['dateRequested'] = $data['dateRequested'];
            if (!empty($data['errors'])) {
                $result['status'] = 'ERRORS';
                $result['errors'] = $data['errors'];
            } else {
                $percent = 0;
                if ($data['pages'] === null) {
                    $allPagesAreDone = false;
                } else {
                    $allPagesAreDone = true;
                    $totalPages = sizeof($data['pages']);
                    $pagePercent = 100 / $totalPages;
                    foreach ($data['pages'] as $pageData) {
                        $allLinksAreDone = false;
                        if (isset($pageData['status'])) {
                            $allLinksAreDone = true;
                        }
                        if (isset($pageData['links'])) {
                            $totalPageLinks = sizeof($pageData['links']);
                            if ($totalPageLinks > 0) {
                                $percent += $pagePercent / 2;
                                $pageLinkPercent = ($pagePercent / 2) / $totalPageLinks;
                                foreach ($pageData['links'] as $linkData) {
                                    if (isset($linkData['status'])) {
                                        $percent += $pageLinkPercent;
                                    } else {
                                        $allLinksAreDone = false;
                                    }
                                }
                            } else {
                                $percent += $pagePercent;
                            }
                        }
                        if (!$allLinksAreDone) {
                            $allPagesAreDone = false;
                        }
                    }
                }
                if ($allPagesAreDone) {
                    $result['status'] = 'DONE';
                } else {
                    $result['status'] = 'RUNNING';
                    $result['percent'] = $percent > 99 ? 99 : round($percent);
                }
            }
        }
        return $result;
    }

    /**
     * Returns the results of an audit.
     *
     * @param string $id The audit ID
     * @return array The audit results in the folling format: ['id'=>..., 'dateRequested'=>..., 'pages'=>[...], 'allowSearchEngines'=>bool]
     */
    public function getResults(string $id): array
    {
        $result = [
            'id' => $id,
            'dateRequested' => null,
            'pages' => [],
            'allowSearchEngines' => null
        ];
        $data = Utilities::getData($id);
        if ($data !== null) {
            $result['dateRequested'] = $data['dateRequested'];
            $result['maxPagesCount'] = isset($data['maxPagesCount']) ? $data['maxPagesCount'] : null;
            foreach ($data['pages'] as $pageID => $pageData) {
                $pageLinksResult = null;
                if (isset($pageData['links'])) {
                    $pageLinksResult = [];
                    foreach ($pageData['links'] as $pageLinkID => $pageLink) {
                        $pageLinksResult[] = [
                            'id' => $pageLinkID,
                            'url' => $pageLink['url'],
                            'status' => $pageLink['status'],
                            'dateChecked' => $pageLink['dateChecked']
                        ];
                    }
                }
                $result['pages'][] = [
                    'id' => $pageID,
                    'url' => $pageData['url'],
                    'status' => isset($pageData['status']) ? $pageData['status'] : null,
                    'dateChecked' => isset($pageData['dateChecked']) ? $pageData['dateChecked'] : null,
                    'title' => isset($pageData['title']) ? $pageData['title'] : null,
                    'description' => isset($pageData['description']) ? $pageData['description'] : null,
                    'keywords' => isset($pageData['keywords']) ? $pageData['keywords'] : null,
                    'content' => isset($pageData['content']) ? $pageData['content'] : null,
                    'links' => $pageLinksResult
                ];
            }
            $result['allowSearchEngines'] = $data['allowSearchEngines'];
        }
        return $result;
    }
}
