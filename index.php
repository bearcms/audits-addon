<?php

/*
 * Audits addon for Bear CMS
 * https://github.com/bearcms/audits-addon
 * Copyright (c) Amplilabs Ltd.
 * Free to use under the MIT license.
 */

use BearCMS\Audits;
use BearFramework\App;
use BearCMS\Audits\Internal\Utilities;

$app = App::get();

$context = $app->contexts->get(__DIR__);

$context->classes
    ->add('BearCMS\Audits', 'classes/Audits.php')
    ->add('BearCMS\Audits\Internal\Utilities', 'classes/Audits/Internal/Utilities.php');

$app->bearCMS->addons
    ->register('bearcms/audits-addon', function (\BearCMS\Addons\Addon $addon) use ($app): void {
        $addon->initialize = function (array $options = []) use ($app): void {

            $app->shortcuts
                ->add('audits', function () {
                    return new Audits();
                });

            \BearCMS\Internal\Config::$appSpecificServerData['g9zmd3al'] = 1;
            if (isset($options['maxPagesCount'])) {
                \BearCMS\Internal\Config::$appSpecificServerData['akz3ajr3'] = (int) $options['maxPagesCount'];
            }

            \BearCMS\Internal\ServerCommands::add('auditsGetList', function () use ($app) {
                return $app->audits->getList()->toArray();
            });

            \BearCMS\Internal\ServerCommands::add('auditsDelete', function (array $data) use ($app) {
                return $app->audits->delete($data['id']);
            });

            \BearCMS\Internal\ServerCommands::add('auditsRequest', function (array $data) use ($app) {
                $maxPagesCount = isset($data['maxPagesCount']) && is_numeric($data['maxPagesCount']) ? (int) $data['maxPagesCount'] : null;
                return $app->audits->request($maxPagesCount);
            });

            \BearCMS\Internal\ServerCommands::add('auditsGetStatus', function (array $data) use ($app) {
                return $app->audits->getStatus($data['id']);
            });

            \BearCMS\Internal\ServerCommands::add('auditsGetResults', function (array $data) use ($app) {
                $result = $app->audits->getResults($data['id']);
                return $result;
            });

            $app->tasks
                ->define('bearcms-audits-initialize', function (string $id): void {
                    Utilities::initializeAudit($id);
                })
                ->define('bearcms-audits-check-page', function (array $data): void {
                    Utilities::checkPage($data['id'], $data['pageID']);
                })
                ->define('bearcms-audits-check-page-link', function (array $data): void { // v1
                    Utilities::checkPageLink($data['id'], $data['pageID'], $data['linkID']);
                })
                ->define('bearcms-audits-check-link', function (array $data): void {
                    Utilities::checkLink($data['id'], $data['url']);
                });
        };
    });
