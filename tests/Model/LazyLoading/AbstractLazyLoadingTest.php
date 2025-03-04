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

namespace Pimcore\Tests\Model\LazyLoading;

use Pimcore\Cache;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\LazyLoading;
use Pimcore\Model\DataObject\RelationTest;
use Pimcore\Model\DataObject\Service;
use Pimcore\Tests\Support\Test\ModelTestCase;
use Pimcore\Tests\Support\Util\TestHelper;

class AbstractLazyLoadingTest extends ModelTestCase
{
    const RELATION_COUNT = 5;

    public function setUp(): void
    {
        parent::setUp();
        TestHelper::cleanUp();

        $this->createRelationObjects();
    }

    public function tearDown(): void
    {
        TestHelper::cleanUp();
        parent::tearDown();
    }

    protected function setUpTestClasses()
    {
        $this->tester->setupPimcoreClass_RelationTest();
        $this->tester->setupFieldcollection_LazyLoadingTest();

        $this->tester->setupFieldcollection_LazyLoadingLocalizedTest();
        $this->tester->setupPimcoreClass_LazyLoading();

        $this->tester->setupObjectbrick_LazyLoadingTest();

        $this->tester->setupObjectbrick_LazyLoadingLocalizedTest();
    }

    protected function createRelationObjects()
    {
        for ($i = 0; $i < 20; $i++) {
            $object = new RelationTest();
            $object->setParent(Service::createFolderByPath('__test/relationobjects'));
            $object->setKey("relation-$i");
            $object->setPublished(true);
            $object->setSomeAttribute("Some content $i");
            $object->save();
        }
    }

    /**
     * @return LazyLoading
     */
    protected function createDataObject(): LazyLoading
    {
        $object = new LazyLoading();
        $object->setParentId(1);
        $object->setKey('lazy1');
        $object->setPublished(true);

        return $object;
    }

    /**
     * @param AbstractObject $parent
     *
     * @return LazyLoading
     *
     * @throws \Exception
     */
    protected function createChildDataObject($parent): LazyLoading
    {
        $object = new LazyLoading();
        $object->setParent($parent);
        $object->setKey('sub-lazy');
        $object->setPublished(true);
        $object->save();

        return $object;
    }

    /**
     * @return RelationTest\Listing
     *
     * @throws \Exception
     */
    protected function loadRelations(): RelationTest\Listing
    {
        $listing = new RelationTest\Listing();
        $listing->setLimit(self::RELATION_COUNT);

        return $listing;
    }

    protected function loadSingleRelation(): RelationTest
    {
        $listing = new RelationTest\Listing();
        $listing->setLimit(1);

        return $listing->load()[0];
    }

    protected function checkSerialization(LazyLoading $object, string $messagePrefix, bool $contentShouldBeIncluded = false)
    {
        $serializedString = serialize($object);
        $this->checkSerializedStringForNeedle($serializedString, ['lazyLoadedFields', 'lazyKeys', 'loadedLazyKeys'], false, $messagePrefix);
        $this->checkSerializedStringForNeedle($serializedString, 'someAttribute";s:14:"Some content', $contentShouldBeIncluded, $messagePrefix);
    }

    protected function checkSerializedStringForNeedle(string $string, $needle, bool $expected, string $messagePrefix = null)
    {
        if (!is_array($needle)) {
            $needle = [$needle];
        }

        foreach ($needle as $item) {
            $this->assertEquals($expected, strpos($string, $item) !== false, $messagePrefix . "Check if '$item' is occuring in serialized data.");
        }
    }

    protected function forceSavingAndLoadingFromCache(Concrete $object, $callback)
    {
        //enable cache
        $cacheEnabled = Cache::isEnabled();
        if (!$cacheEnabled) {
            Cache::enable();
            Cache::getHandler()->setHandleCli(true);
        }

        //save object to cache
        Cache::getHandler()->removeClearedTags($object->getCacheTags());
        Cache::save($object, \Pimcore\Model\Element\Service::getElementCacheTag('object', $object->getId()), [], null, 9999, true);

        Cache\RuntimeCache::clear();
        //reload from cache and check again
        $objectCache = Concrete::getById($object->getId());

        //once more reload object from database to check consistency of
        //data object loaded from cache - see also https://github.com/pimcore/pimcore/issues/12290
        Concrete::getById($object->getId(), ['force' => true]);

        $callback($objectCache);

        if (!$cacheEnabled) {
            Cache::disable();
            Cache::getHandler()->setHandleCli(false);
        }
    }
}
