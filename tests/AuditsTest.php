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
class AuditsTest extends BearCMS\AddonTests\PHPUnitTestCase
{

    /**
     *
     */
    public function testShortcut()
    {
        $app = $this->getApp();
        $this->assertTrue($app->audits instanceof \BearCMS\Audits);
    }
}
