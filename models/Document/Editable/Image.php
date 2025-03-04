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

namespace Pimcore\Model\Document\Editable;

use Pimcore\Model;
use Pimcore\Model\Asset;
use Pimcore\Model\Element;
use Pimcore\Tool\Serialize;

/**
 * @method \Pimcore\Model\Document\Editable\Dao getDao()
 */
class Image extends Model\Document\Editable implements IdRewriterInterface, EditmodeDataInterface
{
    /**
     * ID of the referenced image
     *
     * @internal
     *
     * @var int
     */
    protected $id;

    /**
     * The ALT text of the image
     *
     * @internal
     *
     * @var string
     */
    protected $alt;

    /**
     * Contains the imageobject itself
     *
     * @internal
     *
     * @var Asset\Image|null
     */
    protected $image;

    /**
     * @internal
     *
     * @var bool
     */
    protected $cropPercent = false;

    /**
     * @internal
     *
     * @var float
     */
    protected $cropWidth;

    /**
     * @internal
     *
     * @var float
     */
    protected $cropHeight;

    /**
     * @internal
     *
     * @var float
     */
    protected $cropTop;

    /**
     * @internal
     *
     * @var float
     */
    protected $cropLeft;

    /**
     * @internal
     *
     * @var array
     */
    protected $hotspots = [];

    /**
     * @internal
     *
     * @var array
     */
    protected $marker = [];

    /**
     * The Thumbnail config of the image
     *
     * @internal
     *
     * @var string
     */
    protected $thumbnail;

    /**
     * {@inheritdoc}
     */
    public function getType()
    {
        return 'image';
    }

    /**
     * {@inheritdoc}
     */
    public function getData()
    {
        return [
            'id' => $this->id,
            'alt' => $this->alt,
            'cropPercent' => $this->cropPercent,
            'cropWidth' => $this->cropWidth,
            'cropHeight' => $this->cropHeight,
            'cropTop' => $this->cropTop,
            'cropLeft' => $this->cropLeft,
            'hotspots' => $this->hotspots,
            'marker' => $this->marker,
            'thumbnail' => $this->thumbnail,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getDataForResource()
    {
        return [
            'id' => $this->id,
            'alt' => $this->alt,
            'cropPercent' => $this->cropPercent,
            'cropWidth' => $this->cropWidth,
            'cropHeight' => $this->cropHeight,
            'cropTop' => $this->cropTop,
            'cropLeft' => $this->cropLeft,
            'hotspots' => $this->hotspots,
            'marker' => $this->marker,
            'thumbnail' => $this->thumbnail,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getDataEditmode(): ?array
    {
        $image = $this->getImage();

        if ($image instanceof Asset\Image) {
            $rewritePath = function ($data) {
                if (!is_array($data)) {
                    return [];
                }

                foreach ($data as &$element) {
                    if (array_key_exists('data', $element) && is_array($element['data']) && count($element['data']) > 0) {
                        foreach ($element['data'] as &$metaData) {
                            if ($metaData instanceof Element\Data\MarkerHotspotItem) {
                                $metaData = get_object_vars($metaData);
                            }

                            if (in_array($metaData['type'], ['object', 'asset', 'document'])
                            && $el = Element\Service::getElementById($metaData['type'], $metaData['value'])) {
                                $metaData['value'] = $el;
                            }

                            if ($metaData['value'] instanceof Element\ElementInterface) {
                                $metaData['value'] = $metaData['value']->getRealFullPath();
                            }
                        }
                    }
                }

                return $data;
            };

            $marker = $rewritePath($this->marker);
            $hotspots = $rewritePath($this->hotspots);

            return [
                'id' => $this->id,
                'path' => $image->getRealFullPath(),
                'alt' => $this->alt,
                'cropPercent' => $this->cropPercent,
                'cropWidth' => $this->cropWidth,
                'cropHeight' => $this->cropHeight,
                'cropTop' => $this->cropTop,
                'cropLeft' => $this->cropLeft,
                'hotspots' => $hotspots,
                'marker' => $marker,
                'thumbnail' => $this->thumbnail,
                'predefinedDataTemplates' => $this->getConfig()['predefinedDataTemplates'] ?? null,
            ];
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {
        $config = parent::getConfig();
        if (isset($config['thumbnail']) && !isset($config['focal_point_context_menu_item'])) {
            $thumbConfig = Asset\Image\Thumbnail\Config::getByAutoDetect($config['thumbnail']);
            if ($thumbConfig) {
                foreach ($thumbConfig->getItems() as $item) {
                    if ($item['method'] == 'cover') {
                        $config['focal_point_context_menu_item'] = true;
                        $this->config['focal_point_context_menu_item'] = true;

                        break;
                    }
                }
            }
        }

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public function frontend()
    {
        if (!is_array($this->config)) {
            $this->config = [];
        }

        $image = $this->getImage();

        if ($image instanceof Asset) {
            $thumbnailName = $this->config['thumbnail'] ?? null;
            if ($thumbnailName || $this->cropPercent) {
                // create a thumbnail first
                $autoName = false;

                $thumbConfig = $image->getThumbnailConfig($thumbnailName);
                if (!$thumbConfig && $this->cropPercent) {
                    $thumbConfig = new Asset\Image\Thumbnail\Config();
                }

                if ($this->cropPercent) {
                    $this->applyCustomCropping($thumbConfig);
                    $autoName = true;
                }

                if (isset($this->config['highResolution']) && $this->config['highResolution'] > 1) {
                    $thumbConfig->setHighResolution($this->config['highResolution']);
                }

                // autogenerate a name for the thumbnail because it's different from the original
                if ($autoName) {
                    $hash = md5(Serialize::serialize($thumbConfig->getItems()));
                    $thumbConfig->setName($thumbConfig->getName() . '_auto_' . $hash);
                }

                $deferred = true;
                if (isset($this->config['deferred'])) {
                    $deferred = $this->config['deferred'];
                }

                $thumbnail = $image->getThumbnail($thumbConfig, $deferred);
            } else {
                // we're using the thumbnail class only to generate the HTML
                $thumbnail = $image->getThumbnail();
            }

            $attributes = array_merge($this->config, [
                'alt' => $this->alt,
                'title' => $this->alt,
            ]);

            $removeAttributes = [];
            if (isset($this->config['removeAttributes']) && is_array($this->config['removeAttributes'])) {
                $removeAttributes = $this->config['removeAttributes'];
            }

            // thumbnail's HTML is always generated by the thumbnail itself
            return $thumbnail->getHtml($attributes);
        }

        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function setDataFromResource($data)
    {
        if (strlen($data) > 2) {
            $data = Serialize::unserialize($data);
        }

        $rewritePath = function ($data) {
            if (!is_array($data)) {
                return [];
            }

            foreach ($data as &$element) {
                if (array_key_exists('data', $element) && is_array($element['data']) && count($element['data']) > 0) {
                    foreach ($element['data'] as &$metaData) {
                        // this is for backward compatibility (Array vs. MarkerHotspotItem)
                        if (is_array($metaData)) {
                            $metaData = new Element\Data\MarkerHotspotItem($metaData);
                        }
                    }
                }
            }

            return $data;
        };

        if (array_key_exists('marker', $data) && is_array($data['marker']) && count($data['marker']) > 0) {
            $data['marker'] = $rewritePath($data['marker']);
        }

        if (array_key_exists('hotspots', $data) && is_array($data['hotspots']) && count($data['hotspots']) > 0) {
            $data['hotspots'] = $rewritePath($data['hotspots']);
        }

        $this->id = $data['id'] ?? null;
        $this->alt = $data['alt'] ?? null;
        $this->cropPercent = $data['cropPercent'] ?? null;
        $this->cropWidth = $data['cropWidth'] ?? null;
        $this->cropHeight = $data['cropHeight'] ?? null;
        $this->cropTop = $data['cropTop'] ?? null;
        $this->cropLeft = $data['cropLeft'] ?? null;
        $this->marker = $data['marker'] ?? null;
        $this->hotspots = $data['hotspots'] ?? null;
        $this->thumbnail = $data['thumbnail'] ?? null;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setDataFromEditmode($data)
    {
        $rewritePath = function ($data) {
            if (!is_array($data)) {
                return [];
            }

            foreach ($data as &$element) {
                if (array_key_exists('data', $element) && is_array($element['data']) && count($element['data']) > 0) {
                    foreach ($element['data'] as &$metaData) {
                        $metaData = new Element\Data\MarkerHotspotItem($metaData);
                        if (in_array($metaData['type'], ['object', 'asset', 'document'])) {
                            $el = Element\Service::getElementByPath($metaData['type'], $metaData->getValue());
                            $metaData['value'] = $el;
                        }
                    }
                }
            }

            return $data;
        };

        if (is_array($data)) {
            if (array_key_exists('marker', $data) && is_array($data['marker']) && count($data['marker']) > 0) {
                $data['marker'] = $rewritePath($data['marker']);
            }

            if (array_key_exists('hotspots', $data) && is_array($data['hotspots']) && count($data['hotspots']) > 0) {
                $data['hotspots'] = $rewritePath($data['hotspots']);
            }

            $this->id = $data['id'] ?? null;
            $this->alt = $data['alt'] ?? null;
            $this->cropPercent = $data['cropPercent'] ?? null;
            $this->cropWidth = $data['cropWidth'] ?? null;
            $this->cropHeight = $data['cropHeight'] ?? null;
            $this->cropTop = $data['cropTop'] ?? null;
            $this->cropLeft = $data['cropLeft'] ?? null;
            $this->marker = $data['marker'] ?? null;
            $this->hotspots = $data['hotspots'] ?? null;
            $this->thumbnail = $data['thumbnail'] ?? null;
        }

        return $this;
    }

    /**
     * @return string
     */
    public function getText()
    {
        return $this->alt;
    }

    /**
     * @param string $text
     */
    public function setText($text)
    {
        $this->alt = $text;
    }

    /**
     * @return string
     */
    public function getAlt()
    {
        return $this->getText();
    }

    /**
     * @return string|null
     */
    public function getThumbnailConfig()
    {
        return $this->thumbnail;
    }

    /**
     * @return string
     */
    public function getSrc()
    {
        $image = $this->getImage();
        if ($image instanceof Asset) {
            return $image->getFullPath();
        }

        return '';
    }

    /**
     * @return Asset\Image|null
     */
    public function getImage()
    {
        if (!$this->image) {
            $this->image = Asset\Image::getById($this->getId());
        }

        return $this->image;
    }

    /**
     * @param Asset\Image|null $image
     *
     * @return Model\Document\Editable\Image
     */
    public function setImage($image)
    {
        $this->image = $image;

        if ($image instanceof Asset) {
            $this->setId($image->getId());
        }

        return $this;
    }

    /**
     * @param int $id
     *
     * @return Model\Document\Editable\Image
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return (int) $this->id;
    }

    /**
     * @param string|array|Asset\Image\Thumbnail\Config $conf
     * @param bool $deferred
     *
     * @return Asset\Image\Thumbnail|string
     */
    public function getThumbnail($conf, $deferred = true)
    {
        $image = $this->getImage();
        if ($image instanceof Asset) {
            $thumbConfig = $image->getThumbnailConfig($conf);
            if ($thumbConfig && $this->cropPercent) {
                $this->applyCustomCropping($thumbConfig);
                $hash = md5(Serialize::serialize($thumbConfig->getItems()));
                $thumbConfig->setName($thumbConfig->getName() . '_auto_' . $hash);
            }

            return $image->getThumbnail($thumbConfig, $deferred);
        }

        return '';
    }

    private function applyCustomCropping(Asset\Image\Thumbnail\Config $thumbConfig): void
    {
        $cropConfig = [
            'width' => $this->cropWidth,
            'height' => $this->cropHeight,
            'y' => $this->cropTop,
            'x' => $this->cropLeft,
        ];

        $thumbConfig->addItemAt(0, 'cropPercent', $cropConfig);

        // also crop media query specific configs
        if ($thumbConfig->hasMedias()) {
            foreach ($thumbConfig->getMedias() as $mediaName => $mediaItems) {
                $thumbConfig->addItemAt(0, 'cropPercent', $cropConfig, $mediaName);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isEmpty()
    {
        $image = $this->getImage();
        if ($image instanceof Asset\Image) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheTags(Model\Document\PageSnippet $ownerDocument, array $tags = []): array
    {
        $image = $this->getImage();

        if ($image instanceof Asset) {
            if (!array_key_exists($image->getCacheTag(), $tags)) {
                $tags = $image->getCacheTags($tags);
            }
        }

        $getMetaDataCacheTags = function ($data, $tags) {
            if (!is_array($data)) {
                return $tags;
            }

            foreach ($data as $element) {
                if (array_key_exists('data', $element) && is_array($element['data']) && count($element['data']) > 0) {
                    foreach ($element['data'] as $metaData) {
                        if ($metaData instanceof Element\Data\MarkerHotspotItem) {
                            $metaData = get_object_vars($metaData);
                        }

                        if ($metaData['value'] instanceof Element\ElementInterface) {
                            if (!array_key_exists($metaData['value']->getCacheTag(), $tags)) {
                                $tags = $metaData['value']->getCacheTags($tags);
                            }
                        }
                    }
                }
            }

            return $tags;
        };

        $tags = $getMetaDataCacheTags($this->marker, $tags);
        $tags = $getMetaDataCacheTags($this->hotspots, $tags);

        return $tags;
    }

    /**
     * {@inheritdoc}
     */
    public function resolveDependencies()
    {
        $dependencies = [];
        $image = $this->getImage();

        if ($image instanceof Asset\Image) {
            $key = 'asset_' . $image->getId();

            $dependencies[$key] = [
                'id' => $image->getId(),
                'type' => 'asset',
            ];
        }

        $getMetaDataDependencies = function ($data, $dependencies) {
            if (!is_array($data)) {
                return $dependencies;
            }

            foreach ($data as $element) {
                if (array_key_exists('data', $element) && is_array($element['data']) && count($element['data']) > 0) {
                    foreach ($element['data'] as $metaData) {
                        if ($metaData instanceof Element\Data\MarkerHotspotItem) {
                            $metaData = get_object_vars($metaData);
                        }

                        if ($metaData['value'] instanceof Element\ElementInterface) {
                            $dependencies[$metaData['type'] . '_' . $metaData['value']->getId()] = [
                                'id' => $metaData['value']->getId(),
                                'type' => $metaData['type'],
                            ];
                        }
                    }
                }
            }

            return $dependencies;
        };

        $dependencies = $getMetaDataDependencies($this->marker, $dependencies);
        $dependencies = $getMetaDataDependencies($this->hotspots, $dependencies);

        return $dependencies;
    }

    /**
     * @param float $cropHeight
     *
     * @return $this
     */
    public function setCropHeight($cropHeight)
    {
        $this->cropHeight = $cropHeight;

        return $this;
    }

    /**
     * @return float
     */
    public function getCropHeight()
    {
        return $this->cropHeight;
    }

    /**
     * @param float $cropLeft
     *
     * @return $this
     */
    public function setCropLeft($cropLeft)
    {
        $this->cropLeft = $cropLeft;

        return $this;
    }

    /**
     * @return float
     */
    public function getCropLeft()
    {
        return $this->cropLeft;
    }

    /**
     * @param bool $cropPercent
     *
     * @return $this
     */
    public function setCropPercent($cropPercent)
    {
        $this->cropPercent = $cropPercent;

        return $this;
    }

    /**
     * @return bool
     */
    public function getCropPercent()
    {
        return $this->cropPercent;
    }

    /**
     * @param float $cropTop
     *
     * @return $this
     */
    public function setCropTop($cropTop)
    {
        $this->cropTop = $cropTop;

        return $this;
    }

    /**
     * @return float
     */
    public function getCropTop()
    {
        return $this->cropTop;
    }

    /**
     * @param float $cropWidth
     *
     * @return $this
     */
    public function setCropWidth($cropWidth)
    {
        $this->cropWidth = $cropWidth;

        return $this;
    }

    /**
     * @return float
     */
    public function getCropWidth()
    {
        return $this->cropWidth;
    }

    /**
     * @param array $hotspots
     */
    public function setHotspots($hotspots)
    {
        $this->hotspots = $hotspots;
    }

    /**
     * @return array
     */
    public function getHotspots()
    {
        return $this->hotspots;
    }

    /**
     * @param array $marker
     */
    public function setMarker($marker)
    {
        $this->marker = $marker;
    }

    /**
     * @return array
     */
    public function getMarker()
    {
        return $this->marker;
    }

    /**
     * { @inheritdoc }
     */
    public function rewriteIds(array $idMapping): void
    {
        if (array_key_exists('asset', $idMapping) && array_key_exists($this->getId(), $idMapping['asset'])) {
            $this->setId($idMapping['asset'][$this->getId()]);

            // reset marker & hotspot information
            $this->setHotspots([]);
            $this->setMarker([]);
            $this->setCropPercent(false);
            $this->setImage(null);
        }
    }

    public function __sleep()
    {
        $finalVars = [];
        $parentVars = parent::__sleep();

        $blockedVars = ['image'];

        foreach ($parentVars as $key) {
            if (!in_array($key, $blockedVars)) {
                $finalVars[] = $key;
            }
        }

        return $finalVars;
    }
}
