<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Image optimiser
 * @author    Guy Thomas <gthomas@moodlerooms.com>
 * @copyright Copyright (c) Guy Thomas.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use filter_imageopt\image;

/**
 * Image optimiser - main filter class.
 * @author    Guy Thomas <brudinie@gmail.com>
 * @copyright Copyright (c) Guy Thomas.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filter_imageopt extends moodle_text_filter {

    private $config;

    const REGEXP_IMGSRC = '/<img\s[^\>]*(src=["|\']((?:.*)(pluginfile.php(?:.*)))["|\'])(?:.*)>/isU';

    function __construct(context $context, array $localconfig) {
        global $CFG;

        require_once($CFG->libdir.'/filelib.php');

        $this->config = get_config('filter_imageopt');
        parent::__construct($context, $localconfig);
    }

    /**
     * Get's an image file from the plugin file path.
     *
     * @param str $pluginfilepath pluginfile.php/
     * @return bool|stored_file
     */
    private function get_img_file($pluginfilepath) {
        $tmparr = explode('/', $pluginfilepath);

        $contextid = urldecode($tmparr[1]);
        $component = urldecode($tmparr[2]);

        if ($component === 'mod_label' || $component === 'mod_resource') {
            if (count($tmparr) === 5) {
                $area = urldecode($tmparr[3]);
                $item = 0;
                $filename = urldecode($tmparr[4]);
            } else {
                $area = urldecode($tmparr[3]);
                $item = urldecode($tmparr[4]);
                $filename = urldecode($tmparr[5]);
            }
        } else {
            return false;
        }
        $fs = get_file_storage();
        $file = $fs->get_file($contextid, $component, $area, $item, '/', $filename);
        return $file;
    }

    private function empty_image($width, $height) {
        $svg = <<<EOF
<?xml version="1.0" encoding="utf-8"?>
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">
<svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="$width" height="$height" viewBox="0 0 $width $height">
</svg>
EOF;

        return $svg;
    }

    /**
     * @param array $match (0 - full img tag, 1 src tag and contents, 2 - contents of src, 3 - pluginfile.php/)
     * @return string
     */
    private function apply_loadonvisible(array $match) {
        global $PAGE, $CFG;

        static $jsloaded = false;
        static $imgcount = 0;

        $imgcount ++;

        // This is so we can make the first couple of images load immediately without placeholding.
        if ($imgcount <= $this->config->loadonvisible) {
            return $this->process_image_src($match);
        }

        if (!$jsloaded) {
            $PAGE->requires->js_call_amd('filter_imageopt/imageopt', 'init');
        }

        $jsloaded = true;

        // Full image tag + attributes, etc.
        $img = $match[0];

        if (stripos('data-loadonvisible', $match[0]) !== false) {
            return ($img);
        }

        $maxwidth = $this->config->maxwidth;

        // Does the img have a width or height attribute? If not get it from image file and add it.
        if (stripos($match[0], 'width=') === false || stripos($match[0], 'height=') === false) {
            $file = $this->get_img_file($match[3]);
            if (!$file) {
                return $img;
            }
            $imageinfo = (object) $file->get_imageinfo();
            if (!$imageinfo) {
                return ($match[0]);
            }
            $width = $imageinfo->width;
            $height = $imageinfo->height;
            if ($width > $maxwidth) {
                $ratio = $height / $width;
                $width = $maxwidth;
                $height = $width * $ratio;
            }
            $img = str_ireplace('<img ', '<img width="'.$width.'" height="'.$height.'"', $img);
        } else {
            $doc = new DOMDocument();
            $doc->loadHTML($img);
            $els = $doc->getElementsByTagName('img')[0];
            $width = $els->getAttribute('width');
            $height = $els->getAttribute('height');
            if ($width > $maxwidth) {
                $ratio = $height / $width;
                $width = $maxwidth;
                $height *= $ratio;
            }
        }

        // Replace img src attribute and add data-loadonvisible.

        $file = $this->get_img_file($match[3]);
        if (!$file) {
            $loadonvisible =$match[2];
        } else {
            $filename = $file->get_filename();
            $contextid = $file->get_contextid();
            $component = $file->get_component();
            $area = $file->get_filearea();
            $item = $file->get_itemid();
            $loadonvisible = new moodle_url($CFG->wwwroot.'/pluginfile.php/'.$contextid.'/filter_imageopt/'.$area.'/'.$item.'/'.$component.'/'.$filename);
        }


        $img = str_ireplace('<img ', '<img data-loadonvisible="'.$loadonvisible.'" ', $img);
        $img = str_ireplace($match[1], 'src="data:image/svg+xml;utf8,'.s($this->empty_image($width, $height)).'"', $img);

        return ($img);
    }

    /**
     * @param array $match (0 - full img tag, 1 src tag and contents, 2 - contents of src, 3 - pluginfile.php/)
     * @return string
     */
    private function process_image_src($match) {

        global $CFG;

        if (stripos($match[2], '_opt') !== false) {
            // Already processed.
            return $match[0];
        }

        raise_memory_limit(MEMORY_EXTRA);

        $file = $this->get_img_file($match[3]);

        if (!$file) {
            return $match[0];
        }

        $imageinfo = (object) $file->get_imageinfo();
        if (empty($imageinfo)) {
            return $match[0];
        }

        $maxwidth = $this->config->maxwidth;

        if ($imageinfo->width < $maxwidth) {
            return $match[0];
        }

        $filename = $file->get_filename();
        $contextid = $file->get_contextid();
        $component = $file->get_component();
        $area = $file->get_filearea();
        $item = $file->get_itemid();

        $newsrc = new moodle_url($CFG->wwwroot.'/pluginfile.php/'.$contextid.'/filter_imageopt/'.$area.'/'.$item.'/'.$component.'/'.$filename);

        return str_replace($match[2], $newsrc, $match[0]);
    }

    /**
     * Filter content.
     *
     * @param $text HTML to be processed.
     * @param $options
     * @return string String containing processed HTML.
     */
    public function filter($text, array $options = array()) {
        $filtered = $text; // We need to return the original value if regex fails!

        if (empty($this->config->loadonvisible) || empty($this->config->loadonvisible) < 999) {
            $search = self::REGEXP_IMGSRC;
            $filtered = preg_replace_callback($search, 'self::apply_loadonvisible', $filtered);
        } else {
            $search = self::REGEXP_IMGSRC;
            $filtered = preg_replace_callback($search, 'self::process_image_src', $filtered);
        }

        if (empty($filtered)) {
            return $text;
        }
        return $filtered;
    }
}