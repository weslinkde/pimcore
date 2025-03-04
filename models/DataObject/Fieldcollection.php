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

namespace Pimcore\Model\DataObject;

use Pimcore\Model;
use Pimcore\Model\DataObject;
use Pimcore\Model\Element\DirtyIndicatorInterface;

/**
 * @template TItem of Model\DataObject\Fieldcollection\Data\AbstractData
 *
 * @method array delete(Concrete $object, $saveMode = false)
 * @method Fieldcollection\Dao getDao()
 * @method array load(Concrete $object)
 */
class Fieldcollection extends Model\AbstractModel implements \Iterator, DirtyIndicatorInterface, ObjectAwareFieldInterface
{
    use Model\Element\Traits\DirtyIndicatorTrait;

    /**
     * @internal
     *
     * @var TItem[]
     */
    protected $items = [];

    /**
     * @internal
     *
     * @var string
     */
    protected $fieldname;

    /**
     * @param TItem[] $items
     * @param string|null $fieldname
     */
    public function __construct($items = [], $fieldname = null)
    {
        if (!empty($items)) {
            $this->setItems($items);
        }
        if ($fieldname) {
            $this->setFieldname($fieldname);
        }

        $this->markFieldDirty('_self', true);
    }

    /**
     * @return TItem[]
     */
    public function getItems()
    {
        return $this->items;
    }

    /**
     * @param TItem[] $items
     *
     * @return $this
     */
    public function setItems($items)
    {
        $this->items = $items;
        $this->markFieldDirty('_self', true);

        return $this;
    }

    /**
     * @return string
     */
    public function getFieldname()
    {
        return $this->fieldname;
    }

    /**
     * @param string $fieldname
     *
     * @return $this
     */
    public function setFieldname($fieldname)
    {
        $this->fieldname = $fieldname;

        return $this;
    }

    /**
     * @internal
     *
     * @return Fieldcollection\Definition[]
     */
    public function getItemDefinitions()
    {
        $definitions = [];
        foreach ($this->getItems() as $item) {
            $definitions[$item->getType()] = $item->getDefinition();
        }

        return $definitions;
    }

    /**
     * @param Concrete $object
     * @param array $params
     *
     * @throws \Exception
     */
    public function save($object, $params = [])
    {
        $saveRelationalData = $this->getDao()->save($object, $params);

        /** @var Model\DataObject\ClassDefinition\Data\Fieldcollections $fieldDef */
        $fieldDef = $object->getClass()->getFieldDefinition($this->getFieldname());
        $allowedTypes = $fieldDef->getAllowedTypes();

        $collectionItems = $this->getItems();
        if (is_array($collectionItems)) {
            $index = 0;
            foreach ($collectionItems as $collection) {
                if ($collection instanceof Fieldcollection\Data\AbstractData) {
                    if (in_array($collection->getType(), $allowedTypes)) {
                        $collection->setFieldname($this->getFieldname());
                        $collection->setIndex($index++);
                        $params['owner'] = $collection;

                        // set the current object again, this is necessary because the related object in $this->object can change (eg. clone & copy & paste, etc.)
                        $collection->setObject($object);
                        $collection->getDao()->save($object, $params, $saveRelationalData);
                    } else {
                        throw new \Exception('Fieldcollection of type ' . $collection->getType() . ' is not allowed in field: ' . $this->getFieldname());
                    }
                }
            }
        }
    }

    /**
     * @return bool
     */
    public function isEmpty()
    {
        return count($this->getItems()) < 1;
    }

    /**
     * @param TItem $item
     */
    public function add($item)
    {
        $this->items[] = $item;

        $this->markFieldDirty('_self', true);
    }

    /**
     * @param int $index
     */
    public function remove($index)
    {
        if (isset($this->items[$index])) {
            array_splice($this->items, $index, 1);

            $this->markFieldDirty('_self', true);
        }
    }

    /**
     * @param int $index
     *
     * @return Fieldcollection\Data\AbstractData|null
     */
    public function get($index)
    {
        return $this->items[$index] ?? null;
    }

    private function getByOriginalIndex(?int $index): ?Fieldcollection\Data\AbstractData
    {
        if ($index === null) {
            return null;
        }

        if (is_array($this->items)) {
            foreach ($this->items as $item) {
                if ($item->getIndex() === $index) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * @return int
     */
    public function getCount()
    {
        return count($this->getItems());
    }

    /**
     * Methods for Iterator
     */

    /**
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function rewind()// : void
    {
        reset($this->items);
    }

    /**
     * @return TItem|false
     */
    #[\ReturnTypeWillChange]
    public function current()// : Model\DataObject\Fieldcollection\Data\AbstractData|false
    {
        return current($this->items);
    }

    /**
     * @return int|null
     */
    #[\ReturnTypeWillChange]
    public function key()// : int|null
    {
        return key($this->items);
    }

    /**
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function next()// : void
    {
        next($this->items);
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function valid()// : bool
    {
        return $this->current() !== false;
    }

    /**
     * @param Concrete $object
     * @param string $type
     * @param string $fcField
     * @param int $index
     * @param string $field
     *
     * @throws \Exception
     *
     * @internal
     */
    public function loadLazyField(Concrete $object, $type, $fcField, $index, $field)
    {
        // lazy loading existing can be data if the item already had an index
        $item = $this->getByOriginalIndex($index);
        if ($item && !$item->isLazyKeyLoaded($field)) {
            if ($type == $item->getType()) {
                $fcDef = Model\DataObject\Fieldcollection\Definition::getByKey($type);
                /** @var Model\DataObject\ClassDefinition\Data\CustomResourcePersistingInterface $fieldDef */
                $fieldDef = $fcDef->getFieldDefinition($field);

                $params = [
                    'context' => [
                        'object' => $object,
                        'containerType' => 'fieldcollection',
                        'containerKey' => $type,
                        'fieldname' => $fcField,
                        'index' => $index,
                    ], ];

                $isDirtyDetectionDisabled = DataObject::isDirtyDetectionDisabled();
                DataObject::disableDirtyDetection();

                $data = $fieldDef->load($item, $params);
                DataObject::setDisableDirtyDetection($isDirtyDetectionDisabled);
                $item->setObjectVar($field, $data);
            }
            $item->markLazyKeyAsLoaded($field);
        }
    }

    /**
     * @return Concrete|null
     */
    protected function getObject(): ?Concrete
    {
        $this->rewind();
        $item = $this->current();
        if ($item instanceof Model\DataObject\Fieldcollection\Data\AbstractData) {
            return $item->getObject();
        }

        return null;
    }

    /**
     * @param Concrete|null $object
     *
     * @return $this
     */
    public function setObject(?Concrete $object)
    {
        // update all items with the new $object
        if (is_array($this->getItems())) {
            foreach ($this->getItems() as $item) {
                if ($item instanceof Model\DataObject\Fieldcollection\Data\AbstractData) {
                    $item->setObject($object);
                }
            }
        }

        return $this;
    }

    /**
     * @internal
     */
    public function loadLazyData()
    {
        $items = $this->getItems();
        if (is_array($items)) {
            /** @var Model\DataObject\Fieldcollection\Data\AbstractData $item */
            foreach ($items as $item) {
                $fcType = $item->getType();
                $fieldcolDef = Model\DataObject\Fieldcollection\Definition::getByKey($fcType);
                $fds = $fieldcolDef->getFieldDefinitions();
                foreach ($fds as $fd) {
                    $fieldGetter = 'get' . ucfirst($fd->getName());
                    $fieldValue = $item->$fieldGetter();
                    if ($fieldValue instanceof Localizedfield) {
                        $fieldValue->loadLazyData();
                    }
                }
            }
        }
    }
}
