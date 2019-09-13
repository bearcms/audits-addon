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

$context = $app->contexts->get(__FILE__);

$context->classes
    ->add('BearCMS\Audits', 'classes/Audits.php')
    ->add('BearCMS\Audits\Internal\Utilities', 'classes/Audits/Internal/Utilities.php');

$app->shortcuts
    ->add('audits', function () {
        return new Audits();
    });

$app->bearCMS->addons
    ->register('bearcms/audits-addon', function (\BearCMS\Addons\Addon $addon) use ($app) {
        $addon->initialize = function () use ($app) {
            $context = $app->contexts->get(__FILE__);

            \BearCMS\Internal\Config::$appSpecificServerData['g9zmd3al'] = 1;

            \BearCMS\Internal\ServerCommands::add('auditsGetList', function () use ($app) {
                return $app->audits->getList()->toArray();
            });

            \BearCMS\Internal\ServerCommands::add('auditsDelete', function (array $data) use ($app) {
                return $app->audits->delete($data['id']);
            });

            \BearCMS\Internal\ServerCommands::add('auditsRequest', function () use ($app) {
                return $app->audits->request();
            });

            \BearCMS\Internal\ServerCommands::add('auditsGetStatus', function (array $data) use ($app) {
                return $app->audits->getStatus($data['id']);
            });

            \BearCMS\Internal\ServerCommands::add('auditsGetResults', function (array $data) use ($app) {
                return $app->audits->getResults($data['id']);
            });

            $app->tasks
                ->define('bearcms-audits-initialize', function (string $id) {
                    Utilities::initializeAudit($id);
                })
                ->define('bearcms-audits-check-page', function (array $data) {
                    Utilities::checkPage($data['id'], $data['pageID']);
                })
                ->define('bearcms-audits-check-page-link', function (array $data) {
                    Utilities::checkPageLink($data['id'], $data['pageID'], $data['linkID']);
                });
        };
    });
