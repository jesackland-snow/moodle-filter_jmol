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
 * Jmol filter.
 *
 * @package    filter
 * @subpackage jmol
 * @copyright  2006 Dan Stowell
 * @copyright  2007-2008 Szymon Kalasz Internationalisation strings added as part of GHOP
 * @url        http://moodle.org/mod/forum/discuss.php?d=88201
 * @copyright  20011 Geoffrey Rowland <rowland dot geoff at gmail dot com> Updated for Moodle 2
 * @copyright  20013 Geoffrey Rowland <rowland dot geoff at gmail dot com> Updated to use JSmol
 * @copyright  20015 Geoffrey Rowland <rowland dot geoff at gmail dot com> Updated for Moodle 2.9
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Jmol/JSmol plugin filtering for viewing molecules online.
//
// This filter will replace any links to a chemistry structure file
// (.mol, .sdf, .csmol, .pdb, pdb.gz .xyz, .cml, .mol2, .cif, .mcif etc)
// with with an interactive 3D display of the structure using Jmol/JSmol.
//
// If required, allows customisation of the Jmol object size (default 350 px).
//
// Similarly, allows selection of a few different Jmol control sets depending on the chemical context.
// e.g. small molecule, biological macromolecule, crystal
//
// Also, customisation of the initial display though Jmol scripting.
//
// To activate this filter, go to admin and enable 'jmol'.
//
// Latest JSmol version is available from http://chemapps.stolaf.edu/jmol/jsmol.zip
// Unzipped jsmol folder (and contents) can be used to replace/update the jsmol folder in this bundle.
// Jmol project site: http://jmol.sourceforge.net/
// Jmol interactive scripting documentation(Use with JMOLSCRIPT{ }): http://chemapps.stolaf.edu/jmol/docs/
// Jmol Wiki: http//wiki.jmol.org.
class filter_jmol extends moodle_text_filter {
    public function filter($text, array $options = array()) {
        global $CFG, $PAGE, $bigscreenenabled;
        $wwwroot = $CFG->wwwroot;
        $host = preg_replace('~^.*://([^:/]*).*$~', '$1', $wwwroot);

        // Edit $jmolfiletypes to add/remove chemical structure file types that can be displayed.
        // For more detail see: http://wiki.jmol.org/index.php/File_formats.
        $jmolfiletypes = 'cif\.png|cif|cml\.png|cml|csmol.png\csmol|jmol\.png|jmol|mcif\.png|mcif|mol\.png|mol|mol2\.png|mol2';
        $jmolfiletypes = $jmolfiletypes.'|pdb\.png|pdb\.gz|pdb|pse\.png|pse|sdf\.png|sdf|xyz\.png|xyz';
        // Need to streamline this to use URL $wwwroot syntax. No need to use relative filepaths?
        $search1 = '/<a\\b([^>]*?)href=\"((?:\.|\\\|https?:\/\/' . $host . ')[^\"]+\.('.$jmolfiletypes.'))';
        $search2 = '\??(.*?)\"([^>]*)>(.*?)<\/a>(\s*JMOLSCRIPT\{(.*?)\})?/is';
        $search = $search1.$search2;
        // Bigscreen loaded here, rather than in child iframe, to support Internet Explorer.
        $newtext = preg_replace_callback($search, 'filter_jmol_replace_callback', $text);
        if (($newtext != $text) && !isset($bigscreenenabled)) {
            $bigscreenenabled = true;
            $PAGE->requires->js(new moodle_url('/filter/jmol/js/bigscreen.min.js'));
        }
        return $newtext;
    }
}
function filter_jmol_replace_callback($matches) {
    global $CFG;
    // Convert Moodle language codes to Jmol language codes for Jmol popup menu.
    $moodlelang = current_language();
    $jmollang = array(
        'ar',
        'ca',
        'cs',
        'da',
        'de',
        'el',
        'en_GB',
        'en_US',
        'es',
        'eu',
        'et',
        'fi',
        'fo',
        'fr',
        'hu',
        'id',
        'it',
        'ja',
        'jv',
        'ko',
        'nb',
        'nl',
        'oc',
        'pl',
        'pt',
        'pt_BR',
        'ru',
        'sl',
        'sv',
        'ta',
        'tr',
        'uk',
        'zh_CN',
        'zh_TW'
    );
    $exceptions = array('en' => 'en_GB');
    // First see if this is an exception.
    if (isset($exceptions[$moodlelang])) {
        $moodlelang = $exceptions[$moodlelang];
        // Now look for an exact lang string match.
    } else if (in_array($moodlelang, $jmollang)) {
        $moodlelang = $moodlelang;
        // Now try shortening the moodle lang string and look for a match on the shortened string.
    } else if (in_array(preg_replace('/-.*/', '', $moodlelang), $jmollang)) {
        $moodlelang = preg_replace('/-.*/', '', $moodlelang);
        // All failed - use English.
    } else {
        $moodlelang = 'en';
    }
    // Get language strings with lazy loading.
    $hydrogens = get_string('hydrogens', 'filter_jmol', true);
    $jmolhelp = get_string('jmolhelp', 'filter_jmol', true);
    $jsdisabled = get_string('jsdisabled', 'filter_jmol', true);
    $downloadstructurefile = get_string('downloadstructurefile', 'filter_jmol', true);
    $fullscreen = get_string('fullscreen', 'filter_jmol', true);
    $wwwroot = $CFG->wwwroot;
    // Generate unique id for Jmol frame.
    static $count = 0;
    $count++;
    $id = time() . $count;

    if (!preg_match('/c=(\d{1,2})/', $matches[4], $optmatch)) {
        $optmatch = array(1 => 1);
    }
    $controls = $optmatch[1];

    // JSmol size (width = height) in pixels defined by parameter appended to structure file URL e.g. ?s=200, ?s=300 (default) etc.
    if (preg_match('/s=(\d{1,3})/', $matches[4], $optmatch)) {
        $size = $optmatch[1];
    } else {
        $size = 350;
    }
    // Retrieve the file from the Moodle file API.
    $url = $matches[2];
    $filetype = $matches[3];
    $shortpath = str_replace($wwwroot.'/pluginfile.php', '', $url);
    $args = explode('/', $shortpath);
    $contextid = array_shift($args); // Remove null at index 0.
    $contextid = array_shift($args); // 1st argument at index 1.
    $component = array_shift($args); // 2nd argument at index 2.
    $filearea = array_shift($args);  // 3rd argument at index 3.
    $filename = array_pop($args);    // Last argument.
    $filename = urldecode($filename); // Decode %20, %28, %20 etc from filenames.
    $filestem = str_replace('.'.$filetype, '', $filename);
    $expfilename = str_replace('.png', '', $filename);
    $expfilename = str_replace('.gz', '', $expfilename);
    $expfilename = str_replace('.zip', '', $expfilename);
    if (!$args) {
        $itemid = 0;
        $filepath = '/';
    } else {
        $itemid = array_shift($args);
    }
    if (!is_numeric($itemid)) {
        $itemid = 0;
    }
    if (!$args) {
        $filepath = '/';
    } else {
        $filepath = '/'.implode('/', $args).'/';
    }
    // required for mod_page.
    if ($filearea === 'content') {
        $itemid = 0;
    }
    $fs = get_file_storage();
    $file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);
    // Copy data files from Moodle file API to temporary physical filesystem.
    // Then loaded by JSmol in iframe.
    // Allows loading of binary data files (pdb.gz, pse format etc).
    $pathname = $wwwroot.'/filter/jmol/temp'.$shortpath;
    $destpath = $CFG->dirroot.'/filter/jmol/temp'.$shortpath;
    $destpath = urldecode($destpath);
    // Create temp directories and file, if they don't already exist.
    if ($file) {
        if (!file_exists($destpath)) {
            mkdir(dirname($destpath), 0755, true);
            $file->copy_content_to($destpath);
        } else if (sha1($file->get_content()) != sha1($destpath)) {
            $file->copy_content_to($destpath);
        } else {
            // Touch file to update timestamp.
            touch ($destpath);
        }
    }
    // Controls defined by parameter appended to structure file URL ?c=0, ?c=1 (default), ?c=2 ,?c=3 or ?c=4.
    if (count($matches) > 8) {
        $initscript = preg_replace("@(\s|<br />)+@si", " ",
        str_replace(array("\n", '"', '<br />'), array("; ", "", ""), $matches[8]));
    } else {
        $initscript = '';
    }
    // Setup iframe for Jmol/JSmol.
    return '
<div style="height:100%; width:100%; border: 0; padding: 0; overflow:hidden">
<iframe id = "iframe'.$id.'" allowfullscreen frameborder = "0"
src = "'.new moodle_url('/filter/jmol/iframe.php', array(
    'p' => $pathname,
    'n' => $filestem,
    'f' => $filetype,
    'l' => $moodlelang,
    'c' => $controls,
    'i' => $initscript,
    'id' => $id,
    '_USE' => 'HTML5',
    'DEFER' => true
    )).' "style = "border: 1px solid lightgray; padding: 0px; margin: 0px; height: '.$size.'px; width: '.$size.'px">
</iframe>
</div>
<script>
    YUI().use("node", "event", "resize", function(Y) {
        var resize = new Y.Resize({
            //Selector of the node to resize
            node: "#iframe'.$id.'",
            autoHide: false,
            handles: "br"
        });
        // Fix Jmol aspect ratio and set min max size
        resize.plug(Y.Plugin.ResizeConstrained, {
            preserveRatio: false,
            minWidth: 100,
            minHeight: 100,
            maxWidth: 1000,
            maxHeight: 1000
        });
    });
    // Fullscreen function, using bigscreen polyfill, called from child iframe.
    function fullscreen(x){
        var elem = document.getElementById(x);
        if (BigScreen.enabled) {
            BigScreen.toggle(elem);
        }
    };
</script>';
}
