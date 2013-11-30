<?php
/**
 * DokuWiki Plugin InlineMedia (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Satoshi Sahara <sahara.satoshi@gmail.com>
 *
 * Embed online pdf documents using html5 object tag.
 * Extra support to embed files on Google Drive and SkyDrive.
 * SYNTAX:
 *         {{obj:type width,height noreference > media_id|title}}
 *
 *         {{obj:    ... > ..... }}  obj type resolved by file extention or url pattern
 *         {{obj:pdf ... > ..... }}  explicit adobe pdf
 *         {{obj:swf ... > ..... }}  adobe flash?? (will not be implemented)
 *         {{obj:url ... > url|title}}  display any url page using <iframe> (same as iframe plugin)
 *         {{obj:nodisp ... > ..... }}  nothing embeded
 *
 *        Support plugin gview syntax
 *         {{gview ... > ... }}
 *         {{obj:gview ... > ..... }}
 *
 *        Some types may detected from media URL
 *         {{obj:google.document       ... > ... }}
 *         {{obj:google.spreadsheets   ... > ... }}
 *         {{obj:google.presentation   ... > ... }}
 *         {{obj:google.drawings ... > ... }}
 *         {{obj:skydrive        ... > ... }}
 *
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once DOKU_PLUGIN.'syntax.php';
//require_once DOKU_INC.'inc/confutils.php';

class syntax_plugin_inlinemedia extends DokuWiki_Syntax_Plugin {

    // URL of Google Docs Viwer Service
    const URLgoogleViwer = 'https://docs.google.com/viewer';

    // URL pattern for Google Docs,Sheets,Slides and Drawings 
    const URLgoogleDrivePattern = '#https?://docs\.google\.com/(document|presentation|spreadsheet|drawings)/#';
    // URL pattern for SkyDrive Web App Documents
    const URLskyDrivePattern    = '#https?://skydrive\.live\.com/embed\?cid=#';

    public function getType()  { return 'substition'; }
    public function getPType() { return 'normal'; }
    public function getSort()  { return 305; }
    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('{{obj:.*?\>.*?}}',$mode,'plugin_inlinemedia');
        $this->Lexer->addSpecialPattern('{{gview.*?\>.*?}}',$mode,'plugin_inlinemedia');
    }

    /**
     * build Embed resource URL from media_id
     *
     * @param $id (string)
     * @return (string) mediapath (URL)
     */
    protected function _resource_url($id ='') {
        global $ACT, $ID, $conf;

        // external URLs are always direct without rewriting
        if(preg_match('#^https?://#i', $id)) {
            return $id;
        }
        $id = cleanID($id);
        resolve_mediaid(getNS($ID), $id, $exists);
        //$id = idfilter($id);
        if (!$exists && ($ACT=='preview')) {
            msg('InlineMedia: file not exists: '.$id, -1);
            return false;
        }
        // check access control
        if (!media_ispublic($id) && ($ACT=='preview')) {
            msg('InlineMedia: '.$id.' is NOT public!', 2);
        }
        // check MIME setting of DokuWiki - mime.conf/mime.local.conf
        // Embedding will fail if the media file is to be force_download.
        list($ext, $mtype, $force_download) = mimetype($id);
        if ($force_download) {
            $mediaUrl = _altMedia_url($id);  // try alternative url
            if ($ACT=='preview') {
                msg('InlineMedia: alternative url ('.$mediaUrl.') will be used for '.$id, 2);
            }
        } else {
            switch ($conf['userewrite']){
              case 0: // No URL rewriting
                $mediapath = DOKU_BASE.'lib/exe/fetch.php?media='.$id;
                break;
              case 1: // serverside rewiteing eg. .htaccess file
                $mediapath = DOKU_BASE.'_media/'.$id;
                break;
              case 2: // DokuWiki rewiteing
                $mediapath = DOKU_BASE.'lib/exe/fetch.php/'.$id;
                break;
            }
        }
        return $mediapath;
    }

    /**
     * alternateive url of the media file
     * to avoid forced download based on DokuWiki MIME setting, or
     * to provide another (non-ugly) url for the file.
     *
     * @param $id (string) media id
     * @return (string) URL or pathname of the media file
     */
    protected function _altMedia_url($id) {

        $altMediaBaseUrl = $this->getConf('alternative_mediapath');
        // Example: /data/upload/
        // Be sure to have a leading and a trailing slash!
        // also acceptable base url like http://www.example.com/upload/
        if (empty($altMediaBaseUrl)) $altMediaBaseUrl ='/';
        if ($id[0] == ':') $id = substr($id,1);
        return $altMediaBaseUrl.str_replace(':','/',$id);
    }



    /**
     * handle syntax
     */
    public function handle($match, $state, $pos, &$handler){
        global $ACT, $ID, $conf;

        $opts = array( // set default
                     'type'   => 'unknown',
                     'id'     => '',
                     'title'  => '',
                     'width'  => '98%',
                     'height' => '300px',
                     'reference'  => true,
                     );

        list($params, $media) = explode('>', $match, 2);

        /*
         * handle params part
         */
        $params = substr($params,2); // strip "{{" prefix

        // what type of markup used?
        list($markup, $params) = explode(' ',$params,2);
        if (strpos($markup,':') === false) {
            $opts['type'] = $markup;  // gview syntax used
        } else {
            list($markup, $opts['type']) = explode(':',$markup,2);
            // note: $opts['type'] must be checked later
        }
        // other arguments
        $helper = $this->loadHelper('inlinemedia');
        $attrs = $helper->getAttributes($params);
        foreach ($attrs as $key => $value) {
            $opts[$key] = $value;
        }

        /*
         * handle media part
         */
        $media = trim($media, ' {}');
        list($media, $title) = explode('|',$media,2);
        if (!empty($title)) {
            $opts['title'] = trim($title);
        } else {
            $opts['title'] = $media;
        }

        // Google Docs,Sheets,Slides and Drawings
        if (preg_match(self::URLgoogleDrivePattern, $media, $matches)) {
            $opts['type'] = 'google.'.$matches[1];
            if ($matches[1] == 'drawings') $opts['reference'] = false;
        }
        // SkyDrive Microsoft Web App
        if (preg_match(self::URLskyDrivePattern, $media, $matches)) {
            $opts['type'] = 'skydrive';
        }

        // check if type was omitted in markup like "{{obj: ...>...}}"
        if (empty($opts['type'])) {
            $ext = substr($media, strrpos($media,'.')+1);
            $opts['type'] = $ext;
        }

        // additional check for gview syntax
        // because Google's viewer service requires non-ugly url of media!
        if ( ($opts['type'] == 'gview') && ($conf['userewrite'] == 0)
           && !preg_match('#^https?://#', $media)) {
            // try alternative url
            $opts['id'] = substr( DOKU_URL,0,-1).$this->_altMedia_url($media);
        } else {
            $opts['id'] = $this->_resource_url($media);
        }
        return array($state, $opts);
    }

    /**
     * Render
     */
    public function render($mode, &$renderer, $data) {

        if ($mode != 'xhtml') return false;

        list($state, $opts) = $data;
        if (empty($opts['type'])) return false;

        switch ($opts['type']) {
            case 'nodisp':
                return false;
                break;
            case 'pdf':
                $html = $this->_html_embed_pdf($opts);
                break;
            case 'url':
                $html = $this->_html_iframe($opts);
                break;
            case 'google.document':
                $html = $this->_html_google_document($opts);
                break;
            case 'google.spreadsheet':
                $html = $this->_html_google_spreadsheet($opts);
                break;
            case 'google.presentation':
                $html = $this->_html_google_presentation($opts);
                break;
            case 'google.drawings':
                $html = $this->_html_google_drawings($opts);
                break;
            case 'skydrive':
                $html = $this->_html_ms_webapp($opts);
                break;
            case 'gview':
            default:
                $html = $this->_html_embed_gview($opts);
                break;
        }
        $renderer->doc.=$html;
        return true;
    }

    /**
     * Generate html for sytax {{obj:pdf}}
     */
    protected function _html_embed_pdf($opts) {

        // make reference link
        $referencelink = '<a href="'.utf8_encodeFN($opts['id']).'">'.$opts['id'].'</a>';

        $html = '<div class="obj_container_pdf">'.NL;
        if ($opts['reference']) {
            $html.= sprintf($this->getLang('reference_msg'), $referencelink);
            $html.= '<br />'.NL;
        }
        $html.= '<object data="'.utf8_encodeFN($opts['id']).'"';
        if (!empty($opts['title'])) {
            $html.= ' title="'.$opts['title'].'"';
        }
        $html.= ' style="';
        if ($opts['width'])  { $html.= ' width: '.$opts['width'].';'; }
        if ($opts['height']) { $html.= ' height: '.$opts['height'].';'; }
        $html.= '">'.NL;
        $html.= hsc($opts['title']);
        $html.= '</object>'.NL;
        $html.= '</div>'.NL;

        return $html;
    }

    /**
     * Generate html inside iframe {{obj:url}}
     */
    protected function _html_iframe($opts) {
        $html.= '<iframe src="'.$opts['id'].'"';
        if ($opts['width'])  { $html.= ' width="'.$opts['width'].'"'; }
        if ($opts['height']) { $html.= ' height="'.$opts['height'].'"'; }
        if ($opts['border']) {
            $html.= ' style="border:1px solid grey;"';
        } else {
            $html.= ' frameborder="0"';
        }
        $html.= '">'.hsc($opts['title']).'</iframe>'.NL;
        return $html;
    }


    /**
     * Generate html for sytax {{obj:gview>}} or {{gview>}}
     *
     * @see also: https://docs.google.com/viewer#
     */
    private function _html_embed_gview($opts) {

        // make reference link
        $referencelink = '<a href="'.$opts['id'].'">'.hsc($opts['id']).'</a>';

        $html = '<div class="obj_container_gview">'.NL;
        if ($opts['reference']) {
                $html.= '<div class="obj_note">';
                $html.= sprintf($this->getLang('reference_msg'), $referencelink);
                $html.= '</div>'.NL;
        }
        $html.= '<iframe src="'.self::URLgoogleViwer;
        $html.= '?url='.urlencode($opts['id']);
        $html.= '&embedded=true"';
        $html.= ' style="';
        if ($opts['width'])  { $html.= ' width: '.$opts['width'].';'; }
        if ($opts['height']) { $html.= ' height: '.$opts['height'].';'; }
        //if ($opts['border'] == false) { $html.= ' border: none;'; }
        $html.= ' border: none;';
        $html.= '"></iframe>'.NL;
        $html.= '</div>'.NL;

        return $html;
    }

    /**
     * Generate html for Google Drawings
     */
    private function _html_google_drawings($opts) {

        $html.= '<img src="'.$opts['id'].'"';
        if ($opts['width'])  { $html.= ' width="'.$opts['width'].'"'; }
        if ($opts['height']) { $html.= ' height="'.$opts['height'].'"'; }
        if ($opts['title'])  { $html.= ' title="'.hsc($opts['title']).'"'; }
        $html.= '" />';
        if ($opts['editable']) {
            $url = substr($opts['id'],0,strrpos($opts['id'],'/')).'/edit';
            $html = '<a href="'.$url.'">'.$html.'</a>';
        }
        $html.= NL;
        return $html;
    }
    /**
     * Generate html for Google Document
     */
    private function _html_google_document($opts) {

        if ($opts['reference']) {
            $url = substr($opts['id'],0,strrpos($opts['id'],'/')).'/edit?usp=sharing';
            if ($opts['title']) {
                $referencelink = '<a href="'.$url.'">'.hsc($opts['title']).'</a>';
            } else {
                $referencelink = '<a href="'.$url.'">'.hsc($url).'</a>';
            }
            $html.= '<div class="obj_note">';
            $html.= sprintf($this->getLang('reference_msg'), $referencelink);
            $html.= '</div>'.NL;
        }
        $html.= '<iframe src="'.$opts['id'].'"';
        $html.= ' style="';
        if ($opts['width'])  { $html.= ' width: '.$opts['width'].';'; }
        if ($opts['height']) { $html.= ' height: '.$opts['height'].';'; }
        if ($opts['border'] !== false) {
            $html.= ' border: 1px solid grey;';
        } else {
            $html.= ' border: none;';
        }
        $html.= '"></iframe>'.NL;
        return $html;
    }
    /**
     * Generate html for Google Presentation
     */
    private function _html_google_presentation($opts) {

        if ($opts['reference']) {
            $url = substr($opts['id'],0,strrpos($opts['id'],'/')).'/edit?usp=sharing';
            if ($opts['title']) {
                $referencelink = '<a href="'.$url.'">'.hsc($opts['title']).'</a>';
            } else {
                $referencelink = '<a href="'.$url.'">'.hsc($url).'</a>';
            }
            $html.= '<div class="obj_note">';
            $html.= sprintf($this->getLang('reference_msg'), $referencelink);
            $html.= '</div>'.NL;
        }
        $html.= '<iframe src="'.$opts['id'].'"';
        $html.= ' frameborder="0" allowfullscreen="true" mozallowfullscreen="true" webkitallowfullscreen="true"';
        $html.= ' style="';
        if ($opts['width'])  { $html.= ' width: '.$opts['width'].';'; }
        if ($opts['height']) { $html.= ' height: '.$opts['height'].';'; }
        $html.= '"></iframe>'.NL;
        return $html;
    }
    /**
     * Generate html for Google Spreadsheet
    */
    private function _html_google_spreadsheet($opts) {

        $html = '<iframe src="'.$opts['id'].'"';
        $html.= ' frameborder="0"';
        $html.= ' style="';
        if ($opts['width'])  { $html.= ' width: '.$opts['width'].';'; }
        if ($opts['height']) { $html.= ' height: '.$opts['height'].';'; }
        $html.= '"></iframe>'.NL;
        return $html;
    }

    /**
     * Generate html for Microsoft Web App on SkyDrive
    */
    private function _html_ms_webapp($opts) {

        $html = '<iframe src="'.$opts['id'].'"';
        $html.= ' frameborder="0"';
        if ($opts['width'])  { $html.= ' width="'.$opts['width'].'"'; }
        if ($opts['height']) { $html.= ' height="'.$opts['height'].'"'; }
        $html.= '></iframe>'.NL;
        return $html;
    }
}
