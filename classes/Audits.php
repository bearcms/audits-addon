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
                    if (isset($data['id'])) { // v1 format
                        $result[] = [
                            'id' => $data['id'],
                            'dateRequested' => $data['dateRequested'],
                        ];
                    } else {
                        $result[] = [
                            'id' => $data['i'],
                            'dateRequested' => $data['d'],
                        ];
                    }
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
    public function request(?int $maxPagesCount = null): string
    {
        $id = md5(uniqid());
        $app = App::get();
        $data = [];
        $data['i'] = $id;
        $data['u'] = $app->urls->get('/');
        $data['p'] = null;
        $data['a'] = null;
        $data['e'] = [];
        $data['d'] = date('c');
        $data['m'] = $maxPagesCount;
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
            $result['dateRequested'] = $data['d'];
            if (!empty($data['e'])) {
                $result['status'] = 'ERRORS';
                $result['errors'] = $data['e'];
            } else {
                $percent = 0;
                if ($data['p'] === null) {
                    $allPagesAreDone = false;
                } else {
                    $allPagesAreDone = true;
                    $totalPages = count($data['p']);
                    $pagePercent = $totalPages > 0 ? 100 / $totalPages : 100;
                    foreach ($data['p'] as $pageData) {
                        $allLinksAreDone = false;
                        if (isset($pageData['s'])) {
                            $allLinksAreDone = true;
                        }
                        if (isset($pageData['l'])) {
                            $totalPageLinks = count($pageData['l']);
                            if ($totalPageLinks > 0) {
                                $percent += $pagePercent / 2;
                                $pageLinkPercent = ($pagePercent / 2) / $totalPageLinks;
                                foreach ($pageData['l'] as $linkData) {
                                    if (isset($linkData['s'])) {
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
            'allowSearchEngines' => null,
            'googleSiteVerification' => null
        ];
        $data = Utilities::getData($id);
        if ($data !== null) {
            $result['dateRequested'] = $data['d'];
            $result['maxPagesCount'] = isset($data['m']) ? $data['m'] : null;
            foreach ($data['p'] as $pageID => $pageData) {
                $pageLinksResult = null;
                if (isset($pageData['l'])) {
                    $pageLinksResult = [];
                    foreach ($pageData['l'] as $pageLinkID => $pageLink) {
                        $pageLinksResult[] = [
                            'id' => $pageLinkID,
                            'url' => Utilities::getFullURL($data['u'], $pageLink['u']),
                            'title' => isset($pageLink['t']) ? $pageLink['t'] : null,
                            'status' => $pageLink['s'],
                            'dateChecked' => $pageLink['d']
                        ];
                    }
                }
                $result['pages'][] = [
                    'id' => $pageID,
                    'url' => Utilities::getFullURL($data['u'], $pageData['u']),
                    'status' => isset($pageData['s']) ? $pageData['s'] : null,
                    'dateChecked' => isset($pageData['d']) ? $pageData['d'] : null,
                    'title' => isset($pageData['t']) ? $pageData['t'] : null,
                    'description' => isset($pageData['e']) ? $pageData['e'] : null,
                    'keywords' => isset($pageData['k']) ? $pageData['k'] : null,
                    'content' => isset($pageData['c']) ? $pageData['c'] : null,
                    'openGraphImage' => isset($pageData['g']) ? $pageData['g'] : null,
                    'links' => $pageLinksResult
                ];
            }
            $result['allowSearchEngines'] = $data['a'];
            $result['googleSiteVerification'] = isset($data['g']) ? $data['g'] : null;
        }
        return $result;
    }
}
