<?php
/**
 * Helper Component for the InlineMedia Plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author  Satoshi Sahara <sahara.satoshi@gmail.com>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class helper_plugin_inlinemedia extends DokuWiki_Plugin {

    /* ----------------------------------------------------
     * get each named/non-named arguments as array variable
     *
     * Named arguments is to be given as key="value" (quoted). 
     * Non-named arguments is assumed as boolean.
     *
     * @param $args (string) arguments
     * @return (array) attributes in attr['key']=value
     * ----------------------------------------------------
     */
    public function getAttributes($args='') {

        $attr = array();
        // get named arguments (key="value"), ex: width="100"
        // value must be quoted in argument string.
        $val = "([\"'`])(?:[^\\\\\"'`]|\\\\.)*\g{-1}";
        $pattern = "/(\w+)\s*=\s*($val)/";
        preg_match_all($pattern, $args, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $attr[$match[1]] = substr($match[2],1,-1); // drop quates from value string
            $args = str_replace($match[0], '', $args); // remove parsed substring
        }
        // get flags or non-named arguments, ex: showdate, no-showfooter
        $tokens = preg_split('/\s+/', $args);
        foreach ($tokens as $token) {
            // get width and height
            $matches = array();
            if (preg_match('/(\d+(%|em|pt|px)?)\s*([,xX]\s*(\d+(%|em|pt|px)?))?/',$token,$matches)) {
                if ($matches[4]) {
                    // width and height was given
                    $attr['width'] = $matches[1];
                    if (!$matches[2]) $attr['width'].= 'px';
                    $attr['height'] = $matches[4];
                    if (!$matches[5]) $attr['height'].= 'px';
                    continue;
                } elseif ($matches[2]) {
                    // only height was given
                    $attr['height'] = $matches[1];
                    if (!$matches[2]) $attr['height'].= 'px';
                    continue;
                }
            }
            // get other flags
            //if (preg_match('/[^A-Za-z0-9_-]/',$token)) continue;
            if (preg_match('/^no(?:_|-).+/',$token)) {
                $attr[substr($token,3)] = false; // drop "no-" prefix from key
            } elseif (preg_match('/^!.+/',$token)) {
                $attr[substr($token,1)] = false;  // drop "!" prefix from key
            } else {
                $attr[$token] = true;
            }
        }
        return $attr;
    }


}
