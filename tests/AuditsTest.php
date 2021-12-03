<?php

/*
 * Audits addon for Bear CMS
 * https://github.com/bearcms/audits-addon
 * Copyright (c) Amplilabs Ltd.
 * Free to use under the MIT license.
 */

/**
 * @runTestsInSeparateProcesses
 */
class AuditsTest extends BearFramework\AddonTests\PHPUnitTestCase
{

    protected function initializeApp(bool $setLogger = true, bool $setDataDriver = true, bool $setCacheDriver = true, bool $addAddon = true): \BearFramework\App
    {
        $app = parent::initializeApp($setLogger, $setDataDriver, $setCacheDriver, false);
        $app->addons->add('bearcms/bearframework-addon');
        $app->bearCMS->initialize([]);
        $app->bearCMS->addons->add('bearcms/audits-addon');
        return $app;
    }

    /**
     *
     */
    public function testShortcut()
    {
        $app = $this->getApp();
        $this->assertTrue($app->audits instanceof \BearCMS\Audits);
    }
}
