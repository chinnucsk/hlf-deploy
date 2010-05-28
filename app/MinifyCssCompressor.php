<?php if (!defined('DEPLOY_APP')) die('Illegal access');
 
/**
 * Extended Minify CSS Compressor
 * @package PHP Asset Deployer
 * @author Peng Wang <peng@pengxwang.com>
 * @version 1
 */

class MinifyCssCompressor implements ICssCompressor, ISingleton 
{
    //---------------------------------------
    // PUBLIC VARIABLES
    //---------------------------------------
    
    //---------------------------------------
    // PROTECTED VARIABLES
    //---------------------------------------
    protected static $instance; 
    protected $inHack;
    
    //---------------------------------------
    // CONSTRUCTOR / DESTRUCTOR
    //---------------------------------------
    protected function __construct () 
    {
        if (isset(self::$instance)) {
            throw new RuntimeException('Instance of MinifyCssCompressor already exists.');
            return;
        }
        $this->inHack = false;
    }
    protected function __clone () {}
    public static function instance () 
    {
        if (!isset(self::$instance)) {
            self::$instance = new MinifyCssCompressor();
        }
        return self::$instance;
    }
    public function destroy () 
    {
        if (!isset(self::$instance)) {
            return;
        }
        unset(self::$instance);
    }
    
    /**
     * Cloned from Minify
     * @author Stephen Clay <steve@mrclay.org>
     * @version 2.1.3
     */
    //---------------------------------------
    // PUBLIC METHODS
    //---------------------------------------
    
    public function process ($raw) 
    {
        $compressed = str_replace("\r\n", "\n", $raw);
        // preserve empty comment after '>'
        // http://www.webdevout.net/css-hacks#in_css-selectors
        $compressed = preg_replace('@>/\\*\\s*\\*/@', '>/*keep*/', $compressed);
        // preserve empty comment between property and value
        // http://css-discuss.incutio.com/?page=BoxModelHack
        $compressed = preg_replace('@/\\*\\s*\\*/\\s*:@', '/*keep*/:', $compressed);
        $compressed = preg_replace('@:\\s*/\\*\\s*\\*/@', ':/*keep*/', $compressed);
        // apply callback to all valid comments (and strip out surrounding ws
        $compressed = preg_replace_callback('@\\s*/\\*([\\s\\S]*?)\\*/\\s*@', 
            array($this, 'processComment'), $compressed);
        // remove ws around { } and last semicolon in declaration block
        $compressed = preg_replace('/\\s*{\\s*/', '{', $compressed);
        $compressed = preg_replace('/;?\\s*}\\s*/', '}', $compressed);
        // remove ws surrounding semicolons
        $compressed = preg_replace('/\\s*;\\s*/', ';', $compressed);
        // remove ws around urls
        $compressed = preg_replace('/
                url\\(      # url(
                \\s*
                ([^\\)]+?)  # 1 = the URL (really just a bunch of non right parenthesis)
                \\s*
                \\)         # )
            /x', 'url($1)', $compressed
        );
        // remove ws between rules and colons
        $compressed = preg_replace('/
                \\s*
                ([{;])              # 1 = beginning of block or rule separator 
                \\s*
                ([\\*_]?[\\w\\-]+)  # 2 = property (and maybe IE filter)
                \\s*
                :
                \\s*
                (\\b|[#\'"])        # 3 = first character of a value
            /x', '$1$2:$3', $compressed
        );
        // remove ws in selectors
        $compressed = preg_replace_callback('/
                (?:              # non-capture
                    \\s*
                    [^~>+,\\s]+  # selector part
                    \\s*
                    [,>+~]       # combinators
                )+
                \\s*
                [^~>+,\\s]+      # selector part
                {                # open declaration block
            /x',
            array($this, 'processSelector'), $compressed
        );
        // minimize hex colors
        $compressed = preg_replace(
            '/([^=])#([a-f\\d])\\2([a-f\\d])\\3([a-f\\d])\\4([\\s;\\}])/i', 
            '$1#$2$3$4$5', $compressed
        );
        // remove spaces between font families
        $compressed = preg_replace_callback('/font-family:([^;}]+)([;}])/',
            array($this, 'processFontFamily'), $compressed
        );
        $compressed = preg_replace('/@import\\s+url/', '@import url', $compressed);
        // replace any ws involving newlines with a single newline
        $compressed = preg_replace('/[ \\t]*\\n+\\s*/', "\n", $compressed);
        // separate common descendent selectors w/ newlines (to limit line lengths)
        $compressed = preg_replace('/([\\w#\\.\\*]+)\\s+([\\w#\\.\\*]+){/', 
            "$1\n$2{", $compressed
        );
        // Use newline after 1st numeric value (to limit line lengths).
        $compressed = preg_replace('/
            ((?:padding|margin|border|outline):\\d+(?:px|em)?) # 1 = prop : 1st numeric value
            \\s+
            /x',
            "$1\n", $compressed
        );
        // prevent triggering IE6 bug: http://www.crankygeek.com/ie6pebug/
        $compressed = preg_replace('/:first-l(etter|ine)\\{/', ':first-l$1 {', 
            $compressed);
        // weird bug - PW
        $compressed = str_replace("\n", ' ', $compressed);
        return $compressed;
    }
    
    //---------------------------------------
    // PROTECTED METHODS
    //---------------------------------------
    protected function processComment ($matches) 
    {
        $hasSurroundingWs = (trim($matches[0]) !== $matches[1]);
        $match = $matches[1]; 
        // $match is the comment content w/o the surrounding tokens, 
        // but the return value will replace the entire comment.
        if ($match === 'keep') {
            return '/**/';
        }
        if ($match === '" "') {
            // component of http://tantek.com/CSS/Examples/midpass.html
            return '/*" "*/';
        }
        if (preg_match('@";\\}\\s*\\}/\\*\\s+@', $match)) {
            // component of http://tantek.com/CSS/Examples/midpass.html
            return '/*";}}/* */';
        }
        if ($this->inHack) {
            // inversion: feeding only to one browser
            if (preg_match('@
                    ^/               # comment started like /*/
                    \\s*
                    (\\S[\\s\\S]+?)  # has at least some non-ws content
                    \\s*
                    /\\*             # ends like /*/ or /**/
                @x', $match, $n)) {
                // end hack mode after this comment, but preserve the hack and comment content
                $this->inHack = false;
                return "/*/{$n[1]}/**/";
            }
        }
        if (substr($match, -1) === '\\') { // comment ends like \*/
            // begin hack mode and preserve hack
            $this->inHack = true;
            return '/*\\*/';
        }
        if ($match !== '' && $match[0] === '/') { // comment looks like /*/ foo */
            // begin hack mode and preserve hack
            $this->inHack = true;
            return '/*/*/';
        }
        if ($this->inHack) {
            // a regular comment ends hack mode but should be preserved
            $this->inHack = false;
            return '/**/';
        }
        // Issue 107: if there's any surrounding whitespace, it may be important, so 
        // replace the comment with a single space
        // remove all other comments
        return $hasSurroundingWs ? ' ' : '';        
    }
    protected function processSelector ($matches)
    {
        // remove ws around the combinators
        return preg_replace('/\\s*([,>+~])\\s*/', '$1', $matches[0]);        
    }
    protected function processFontFamily ($matches)
    {
        $matches[1] = preg_replace('/
                \\s*
                (
                    "[^"]+"      # 1 = family in double quotes
                    |\'[^\']+\'  # or 1 = family in single quotes
                    |[\\w\\-]+   # or 1 = unquoted family
                )
                \\s*
            /x', '$1', $matches[1]
        );
        return 'font-family:' . $matches[1] . $matches[2];        
    }
}