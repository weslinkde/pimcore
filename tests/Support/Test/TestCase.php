<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Tests\Support\Test;

use Codeception\Test\Unit;
use Pimcore\Tests\Support\Util\TestHelper;

abstract class TestCase extends Unit
{
    /**
     * @var bool
     */
    protected $cleanupDbInSetup = true;

    protected $backupGlobalsExcludeList = ['IDE_EVAL_CACHE'];     // xdebug

    /**
     * Determine if the test needs a DB connection (will be skipped if no DB is present)
     *
     * @return bool
     */
    protected function needsDb()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->needsDb()) {
            TestHelper::checkDbSupport();

            // every single test assumes a clean database
            if ($this->cleanupDbInSetup) {
                TestHelper::cleanUp();
            }
        }

        \Pimcore::collectGarbage();
    }
}
