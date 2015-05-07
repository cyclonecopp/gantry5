<?php

/**
 * @package   Gantry5
 * @author    RocketTheme http://www.rockettheme.com
 * @copyright Copyright (C) 2007 - 2015 RocketTheme, LLC
 * @license   Dual License: MIT or GNU/GPLv2 and later
 *
 * http://opensource.org/licenses/MIT
 * http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Gantry Framework code that extends GPL code is considered GNU/GPLv2 and later
 */

namespace Gantry\Component\Layout;

use Gantry\Component\Filesystem\Folder;
use Gantry\Framework\Configurations;
use Gantry\Framework\Gantry;
use RocketTheme\Toolbox\ArrayTraits\ArrayAccess;
use RocketTheme\Toolbox\ArrayTraits\Export;
use RocketTheme\Toolbox\ArrayTraits\ExportInterface;
use RocketTheme\Toolbox\ArrayTraits\Iterator;
use RocketTheme\Toolbox\File\JsonFile;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceIterator;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

/**
 * Layout
 */
class Layout implements \ArrayAccess, \Iterator, ExportInterface
{
    use ArrayAccess, Iterator, Export;

    protected static $instances = [];

    public $name;
    public $preset;
    public $meta;
    protected $exists;
    protected $items;
    protected $references;
    protected $types;

    public static function presets()
    {
        $gantry = Gantry::instance();

        /** @var UniformResourceLocator $locator */
        $locator = $gantry['locator'];

        /** @var UniformResourceIterator $iterator */
        $iterator = $locator->getIterator(
            'gantry-layouts://',
            UniformResourceIterator::CURRENT_AS_SELF | UniformResourceIterator::UNIX_PATHS | UniformResourceIterator::SKIP_DOTS
        );

        $files = [];
        /** @var UniformResourceIterator $info */
        foreach ($iterator as $info) {
            $name = $info->getBasename('.yaml');
            if (!$info->isFile() || $info->getExtension() != 'yaml' || $name[0] == '.') {
                continue;
            }
            $files[] = $name;
        }

        sort($files);

        $results = ['user' => [], 'system' => []];
        foreach ($files as $preset) {
            $scope = $preset && $preset[0] !== '_' ? 'user' : 'system';
            $results[$scope][$preset] = ucwords(trim(preg_replace(['|_|', '|/|'], [' ', ' / '], $preset)));
        }

        return $results;
    }

    public static function preset($name)
    {
        $gantry = Gantry::instance();

        /** @var UniformResourceLocator $locator */
        $locator = $gantry['locator'];
        $filename = $locator->findResource("gantry-layouts://{$name}.yaml");

        if (!$filename) {
            throw new \RuntimeException('Preset not found', 404);
        }

        return LayoutReader::read($filename);
    }

    /**
     * @param  string $name
     * @return Layout
     */
    public static function instance($name)
    {
        if (!isset(static::$instances[$name])) {
            static::$instances[$name] = static::load($name);
        }

        return static::$instances[$name];
    }

    /**
     * @param string $name
     * @param array $items
     * @param string $preset
     */
    public function __construct($name, array $items = null, $preset = null)
    {
        $this->name = $name;
        $this->preset = $preset;
        $this->items = (array) $items;
        $this->exists = $items !== null;

        // If items have preset, use it.
        if (!empty($this->items['preset'])) {
            $this->preset = $this->items['preset'];
        }

        // Set metadata.
        $this->meta = !empty($this->items['meta']) ? $this->items['meta'] : [];
        $this->meta += ['image' => 'gantry-admin://images/layouts/default.png'];

        unset($this->items['preset'], $this->items['meta']);
    }

    /**
     * @return bool
     */
    public function exists()
    {
        return $this->exists;
    }

    /**
     * @return array
     */
    public function references()
    {
        if (!isset($this->references)) {
            $this->initReferences();
        }

        return $this->references;
    }

    /**
     * @param string $type
     * @param string $subtype
     * @return array
     */
    public function referencesByType($type = null, $subtype = null)
    {
        if (!isset($this->references)) {
            $this->initReferences();
        }

        if (!$type) {
            return $this->types;
        } elseif (!$subtype) {
            return isset($this->types[$type]) ? $this->types[$type] : [];
        }
        return isset($this->types[$type][$subtype]) ? $this->types[$type][$subtype] : [];
    }

    /**
     * Return list of positions (key) with their titles (value).
     *
     * @return array Array of position => title
     */
    public function positions()
    {
        $positions = $this->referencesByType('position', 'position');

        $list = [];
        foreach($positions as $position) {
            if (!isset($position->attributes->key)) {
                continue;
            }
            $list[$position->attributes->key] = $position->title;
        }

        return $list;
    }

    /**
     * @param string $id
     * @return \stdClass
     */
    public function find($id)
    {
        if (!isset($this->references)) {
            $this->initReferences();
        }

        if (!isset($this->references[$id])) {
            return new \stdClass;
        }

        return $this->references[$id];
    }

    /**
     * @param array $items
     */
    protected function initReferences(array $items = null)
    {
        if ($items === null) {
            $items = $this->items;
            $this->references = [];
            $this->types = [];
        }

        foreach ($items as $item) {
            if (is_object($item)) {
                if (isset($item->id)) {
                    $this->references[$item->id] = $item;
                }
                $type = $item->type;
                $subtype = !empty($item->subtype) ? $item->subtype : $type;

                $this->types[$type][$subtype][] = $item;

                if (isset($item->children) && is_array($item->children)) {
                    $this->initReferences($item->children);
                }
            }
        }
    }

    /**
     * @param  string $name
     * @return static
     */
    protected static function load($name)
    {
        $gantry = Gantry::instance();

        /** @var UniformResourceLocator $locator */
        $locator = $gantry['locator'];

        $layout = null;
        $preset = null;
        $filename = $locator("gantry-config://{$name}/layout.yaml");

        // If layout file doesn't exists, figure out what preset was used.
        if (!$filename) {
            /** @var Configurations $configurations */
            $configurations = $gantry['configurations'];

            $preset = $configurations->preset($name);
            $filename = $locator("gantry-layouts://{$preset}.yaml");
        }

        if ($filename) {
            $layout = LayoutReader::read($filename);
        }

        return new static($name, $layout, $preset);
    }
}
