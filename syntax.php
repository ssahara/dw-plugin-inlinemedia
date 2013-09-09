<?php
/**
 * DokuWiki Plugin InlineMedia (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Satoshi Sahara <sahara.satoshi@gmail.com>
 *
 * embed a online pdf document using html5 object tag.
 * SYNTAX:
 *         {{obj:pdf ... > ..... }}
 *         {{obj:pdf width,height noreference > media_id|title}}
 *
 *         {{obj:    ... > ..... }}  default obj type resolved by file extention
 *         {{obj:pdf ... > ..... }}  adobe pdf
 *         {{obj:swf ... > ..... }}  adobe flush?? (planned)
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once DOKU_PLUGIN.'syntax.php';
require_once DOKU_INC.'inc/confutils.php';

class syntax_plugin_inlinemedia extends DokuWiki_Syntax_Plugin {
    public function getType()  { return 'substition'; }
    public function getPType() { return 'normal'; }
    public function getSort()  { return 305; }
    public function connectTo($mode) {
        //$this->Lexer->addSpecialPattern('{{obj:.*?\>.*{{.+?}}[^\n]*}}',$mode,'plugin_inlinemedia');
        $this->Lexer->addSpecialPattern('{{obj:.*?\>.*?}}',$mode,'plugin_inlinemedia');
    }

    /**
     * show debug message
     * usage: $this->_debug('message', [-1,0,1,2],__LINE__,__FILE__);
     */
    protected function _debug($message, $err, $line, $file) {
        if ($this->GetConf('debug')) {
            msg($message, $err, $line, $file);
        }
    }

    /**
     * build Embed resource URL from media_id
     * MIME設定により強制ダウンロードとなるファイルでも、埋め込みできるようにする。
     * ここではURLエンコードはしない。render()側で処理する。
     */
    protected function _resource_url($id ='') {
        global $ACT, $ID, $conf;

        $this->_debug('media $id='.$id, 0, __LINE__, __FILE__);

        // external URLs are always direct without rewriting
        // URL（http://）であれば DWのMIME設定は気にする必要はない
        if(preg_match('#^(https?|ftp)://#i', $id)) {
            return $id;
        } else {
            // メディアIDの絶対パスを解決する
            $id = cleanID($id);
            resolve_mediaid(getNS($ID),$id,$exists);
            //$id = idfilter($id);
            if (!$exists && ($ACT=='preview')) {
                msg('InlineMedia: file not exists: '.$id, -1);
                // 存在しない場合、予期しないものが表示されてしまうのを防止…
                return false;
            }
        }
        // check access control
        if (!media_ispublic($id) && ($ACT=='preview')) {
            msg('InlineMedia: '.$id.' is NOT public!', 2);
        }
        
        // check MIME setting of DW - mime.conf/mime.local.conf
        list($ext, $mtype, $force_download) = mimetype($id);
        if ($force_download) {
            // DW配下ではないURLを使う
            // alternative_mediapath が /始まりのときはDocument Rootからのパス
            // それ以外は DOKU_URL からのバスとなる。
            $mediapath = $this->GetConf('alternative_mediapath');
            if (empty($mediapath)) {
                if ($ACT=='preview') {
                    msg('InlineMedia: embedding .'.$ext.' file restricted because of MIME type (force download)', -1);
                }
                return false;
            } else {
                $mediapath.= str_replace(':','/',$id);
                $mediapath = str_replace('//','/',$mediapath);
                if ($ACT=='preview') {
                    msg('InlineMedia: alternative mediapath used: '.$mediapath, 0);
                }
            }
            // !!! EXPERIMENTAL : WEB SITE SPECIFIC FEATURE !!!
            // 埋め込みファイルのMIMEタイプが !で始まる場合、強制ダウンロードになるので、埋め込み不可能。
            // 代替策として、DATA/mediaを指すsimbolic linkを用意する。
            // Webサーバ側のMIME設定次第で強制ダウンロードになるかも。
            // userewrite=1のとき、代替メディアパスを"DOKU_URL/_media"にすると、
            // DWのMIME設定が有効となるので、強制ダウンロード対策にならなかった。
            // したがって、"DOKU_URL/media"のようなDW管轄外のURLとすること。
            //
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
        $this->_debug('$mediapath='.$mediapath.' $force_DL='.(int)$force_download, 0);
        
        
        return $mediapath;
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
                     'width'  => '100%',
                     'height' => '300px',
                     'reference'  => true,
                     );

        list($params, $media) = explode('>',$match,2);

        /*
         * handle media part
         */
        $media = trim($media, ' {}');
        if(strpos($media,'|') !== false) {
            list($media, $title) = explode('|',$media,2);
        }
        $media = $this->_resource_url($media);

        $opts['id'] = trim($media);
        if (!empty($title)) $opts['title'] = trim($title);

        /*
         * handle params part
         */
        // split phrase by white space (" ", \r, \t, \n, \f)
        $tokens = preg_split('/\s+/', $params);

        // what type of markup used? (check first token)
        $markup = array_shift($tokens); // first param
        if ($markup !== "{{obj:") {
            $opts['type'] = substr($markup,6); //strip "{{obj:"
        } else {
            $opts['type'] = substr($media, strrpos($media,'.')+1);
        }

        // see other tokens
        foreach ($tokens as $token) {
            // get width and height
            $matches=array();
            if (preg_match('/(\d+(%|em|pt|px)?)\s*([,xX]\s*(\d+(%|em|pt|px)?))?/',$token,$matches)){
                if ($matches[4]) {
                    // width and height was given
                    $opts['width'] = $matches[1];
                    if (!$matches[2]) $opts['width'].= 'px';
                    $opts['height'] = $matches[4];
                    if (!$matches[5]) $opts['height'].= 'px';
                    continue;
                } elseif ($matches[2]) {
                    // only height was given
                    $opts['height'] = $matches[1];
                    if (!$matches[2]) $opts['height'].= 'px';
                    continue;
                }
            }
            // get reference option, ie. whether show original document url?
            if (preg_match('/no(reference|link)/',$token)){
                $opts['reference'] = false;
                continue;
            }
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
            default:
                $html = $this->_html_embed_pdf($opts);
                break;
        }
        $renderer->doc.=$html;
        return true;
    }

     /**
      * Generate html for sytax {{obj:pdf}}
      */
    function _html_embed_pdf($opts) {

        // make reference link
        $referencelink = '<a href="'.utf8_encodeFN($opts['id']).'">'.$opts['id'].'</a>';

        $html = '<div class="inlinemedia_container_pdf">'.NL;
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
        $html.= '</object>'.NL;
        $html.= '</div>'.NL;

        return $html;
    }

     /**
      * Generate html for sytax {{obj:xxx}} (planned)
      *
      */

}
