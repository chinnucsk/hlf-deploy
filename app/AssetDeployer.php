<?php if (!defined('DEPLOY_APP')) die('Illegal access');

require 'interfaces.php';
require 'ClosureCompilerService.php';
require 'MinifyCssCompressor.php';

/**
 * Handles asset harvesting, packaging, and deployment.
 * Public methods that have paths as parameters will 
 * look for available paths based on $devStylePath and 
 * $devScriptPath.
 * @todo work with dynamically generated includes
 * @package default
 * @author Peng Wang
 */

class AssetDeployer implements IAssetDeployer, ISingleton, IExtendedLanguage
{
    //---------------------------------------
    // PUBLIC VARIABLES
    //---------------------------------------
    public $devScriptPath;
    public $prodScriptPath;
    
    public $devStylePath;
    public $prodStylePath;
    
    public $ds;
    public $deepTraversal;
    public $timestamped;
    public $maxLineLength; // for editors like TextMate
    
    public $mode;
    
    //---------------------------------------
    // CLASS CONSTANTS
    //---------------------------------------
    const PROGRESSIVE_ENHANCEMENT = 'progressive enhancement deployer mode';
    const PARENT_STYLE = 'parent style type';
    
    //---------------------------------------
    // PROTECTED VARIABLES
    //---------------------------------------
    protected static $instance;

    protected $closureCompiler;
    protected $scripts;
    protected $scriptCalls;

    protected $cssCompressor;
    protected $styles;
    protected $styleCalls;    

    protected $store;
    
    //---------------------------------------
    // CONSTRUCTOR
    //---------------------------------------
    protected function __construct () 
    {
        if (isset(self::$instance)) {
            throw new RuntimeException('Instance of AssetDeployer already exists.');
            return;
        }
        $this->ds = DIRECTORY_SEPARATOR;
        $this->deepTraversal = true;
        $this->timestamped = false;
        $this->maxLineLength = 150;
        $this->mode = self::PROGRESSIVE_ENHANCEMENT;
        $this->store = array();
        $this->closureCompiler = ClosureCompilerService::instance();
        $this->clearScripts();
        $this->cssCompressor = MinifyCssCompressor::instance();
        $this->clearStyles();
    }
    protected function __clone () {}
    public static function instance () 
    {
        if (!isset(self::$instance)) {
            self::$instance = new AssetDeployer();
        }
        return self::$instance;
    }
    public function destroy () 
    {
        
    }
    
    //---------------------------------------
    // PUBLIC METHODS
    //---------------------------------------
    
    // FILE MANAGER METHODS
    
    /**
     * Recursively maps a directory
     * Supports results returned via callback
     */
    public function traverseDirectory ($path, $callbackInfo = null, 
                                       $typesToKeep = array(), $typesToSkip = array(), 
                                       $skipMeta = true, $dirsToSkip = array()) 
    {
        // $handle, $name, $type, $child, $result
        $result = array();
        if ($handle = @opendir($path)) {
            $path = rtrim($path, $this->ds) . $this->ds; // for certainty
            while (($name = readdir($handle)) !== false) 
            {
                if ($skipMeta && strncmp($name, '.', 1) === 0) {
                    // .DS_STORE, .svn, .git, .htaccess, etc.
                    continue;
                } 
                if ($type = $this->arrayElement('extension', pathinfo($name))) {
                    if (!empty($typesToSkip) && in_array($type, $typesToSkip)) {
                        continue;
                    } elseif (!empty($typesToKeep) && !in_array($type, $typesToKeep)) {
                        continue;
                    }
                }
                if ($this->deepTraversal && @is_dir($path . $name) && !in_array($name, $dirsToSkip)) {
                    $child = $this->traverseDirectory(
                        $path . $name . $this->ds, 
                        $callbackInfo, $typesToKeep, $typesToSkip, $skipMeta, $dirsToSkip
                    );
                    $result[$name] = $child;
                } else {
                    if (isset($callbackInfo)) {
                        $result[] = call_user_func($callbackInfo, $path . $name);
                    } else {
                        $result[] = $name;
                    }
                }
            }
        }
        closedir($handle);
        return $result;
    }
    public function buildPath ()
    {
        $segments = func_get_args();
        $dsAlt = ($this->ds == '/') ? '\\' : '/';
        $isFirst = true;
        foreach ($segments as $i => &$segment) 
        {
            if (is_array($segment)) {
                throw new RuntimeException('No multidimensional arrays');
                return '';
            } elseif (empty($segment)) {
                unset($segments[$i]);
            }
            $segment = str_replace($dsAlt, $this->ds, (!$isFirst ? trim($segment, $ds) : $segment));
            $isFirst = false;
        }
        return implode($this->ds, $segments);
    }
    public function publishFile ($path, $name, $content) 
    {
        $path = rtrim($path, $this->ds) . $this->ds;
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
        file_put_contents($path . $name, $content);
        $this->log("Published file ${path}${name}"
            // , $content
            );
    }
    
    // TEMPLATE MANIPULATOR METHODS
    
    public function replaceNodes () 
    {
        
    }
    
    // EXTENDED LANGUAGE METHODS
    
    public function arrayElement ($key, $array, $default = false) 
    {
        return (!isset($array[$key])) ? $default : $array[$key];
    }
    public function isEmptyString ($string) 
    {
        return (strlen(trim($string)) === 0);
    }
    public function log ($string, $object = null) 
    { 
        print "<pre>$string";
        if (isset($object)) {
            print ':' . str_repeat(PHP_EOL, 2);
            var_export($object);
        } 
        print '</pre><br/>';
    }
    
    // ASSET DEPLOYER METHODS
    
    /**
     * Populate content stores
     */
    public function addScripts ($path = '')
    {
        if (is_file($path)) {
            $this->addScript($path);
            $this->log("Added and cooked script $path");
            return true;
        } elseif (is_dir($path)) {
            $this->traverseDirectory($path, array($this, 'addScript'), array('js'), 
                                     array(), true, array('production'));
            $this->log("Added and cooked scripts in $path"
                // , $this->scripts
                );
            return true;
        } elseif (strpos($path, $this->devScriptPath) !== 0) {
            $path = $this->buildPath($this->devScriptPath, $path);
            if (!$this->addScripts($path)) {
                throw new RuntimeException('Script path is still invalid.');
                return false;
            } else {
                return true;
            }
        } 
        return false;
    }
    public function addStyles ($path = '')
    {
        if (is_file($path)) {
            $this->addStyle($path);
            $this->log("Added and cooked style $path");
            return true;
        } elseif (is_dir($path)) {
            $this->traverseDirectory($path, array($this, 'addStyle'), array('css'), 
                                     array(), true, array('production'));
            $this->log("Added and cooked styles in $path"
                // , $this->styles
                );
            return true;
        } elseif (strpos($path, $this->devStylePath) !== 0) {
            $path = $this->buildPath($this->devStylePath, $path);
            if (!$this->addStyles($path)) {
                throw new RuntimeException('Script path is still invalid.');
                return false;
            } else {
                return true;
            }
        } 
        return false;
    }
    /**
     * Create and configure final files
     * Scripts are published individually, and select scripts are bundled
     * Styles are bundled, expect specified ones for progressive enhancement
     * @todo callee for styles
     */
    public function publishScripts ($callee = null, $name = null) 
    {
        $this->checkScriptPath();
        $fullName = $this->buildFileName((isset($name) ? $name : 'production'), 'js');
        if (isset($callee)) {
            $this->getScriptCalls($callee);
            $output = '';
            foreach ($this->scripts as $scriptName => $script) 
            {
                $output .= $script['head'] . PHP_EOL . $script['body'] . PHP_EOL;                
            }
            $this->publishFile($this->prodScriptPath, $fullName, $output);
            $this->log('Bundled scripts ' . implode(',', $this->scriptCalls[$callee])
                // , $output
                );
        } else {
            foreach ($this->scripts as $scriptName => $script) 
            {
                $output = $script['head'] . PHP_EOL . $script['body'];
                $this->publishFile($this->prodScriptPath, "$scriptName.js", $output);
                if (isset($callee) && in_array($scriptName, $this->scriptCalls[$callee])) {
                    $output .= $singleOutput . PHP_EOL;
                }
            }
        }
    }
    public function publishStyles ($callee = null, $name = null)
    {
        $this->checkStylePath();
        if ($this->mode === self::PROGRESSIVE_ENHANCEMENT) {
            foreach ($this->styles as $styleName => &$style)
            {
                $style['publishName'] = $fullName = $this->buildFileName("$styleName.min", 'css');
                $output = $style['head'] . PHP_EOL;
                if ($this->isEmptyString(trim($style['body']))) { // TODO - refine this patch
                    continue;
                }
                if ($this->arrayElement('type', $style) === self::PARENT_STYLE) {
                    $style['body'] = preg_replace(
                        '/(@import.*)((?<!\.min)\.css)(.*)/',
                        '$1.min.css$3', $style['body']
                    );
                }
                $output .= $style['body'] . PHP_EOL;
                $this->publishFile($this->prodStylePath, $fullName, $output);
            }
        } else {
            $fullName = $this->buildFileName((isset($name) ? $name : 'production'), 'css');
            $output = '';
            foreach ($this->styles as $style) 
            {
                $output .= $style['head'] . PHP_EOL;
                $output .= $style['body'] . PHP_EOL;
            }
            $this->publishFile($this->prodStylePath, $fullName, $output);
        }
    }
    /**
     * Update dependent pages
     * 
     */
    public function updateScriptCalls ($callee = null, $revert = false)
    {
        if (!isset($callee)) {
            throw new RuntimeException('Template cannot be empty');
            return;
        }
        if (is_file($callee)) {
            $contents = file_get_contents($callee);
        }
    }
    public function updateStyleCalls ($callee = null, $revert = false)
    {
        if (!isset($callee)) {
            throw new RuntimeException('Template cannot be empty');
            return;
        } 
        if (is_file($callee)) {
            $contents = file_get_contents($callee);
            if ($this->mode === self::PROGRESSIVE_ENHANCEMENT) {
                $pathDiff = trim(str_replace($this->devStylePath, '', $this->prodStylePath), $this->ds);
                foreach ($this->styles as $name => $style) {
                    if ($revert) {
                        $pattern = '/(<link\b.+href="[^"]+)(' 
                            . addSlashes($pathDiff) . '\/' . preg_quote($style['publishName']) 
                            . ')(".*\/\s?>)/i';
                        $replacement = '$1' . str_replace('.min.css', '.css', $style['publishName']) . '$3';
                    } else {
                        $name .= '.css';
                        $pattern = '/(<link\b.+href="[^"]+)(' . preg_quote($name) . ')(".*\/\s?>)/i';
                        $replacement = '$1' . str_replace('.css', '.min.css', "$pathDiff/$name") . '$3';
                    }
                    $contents = preg_replace($pattern, $replacement, $contents);
                }
            } else {
                // TODO
            }
            $this->log("Updated calls in $callee"
                // , htmlentities($contents)
                );
            file_put_contents($callee, $contents);
            return true;
        } elseif (strpos($callee, $this->devStylePath) !== 0) { 
            $callee = $this->buildPath($this->devStylePath, $callee);
            if (!$this->updateStyleCalls($callee, $revert)) {
                throw new RuntimeException('Template path is still invalid.');
            } else {
                return true;
            }
        } 
        return false;
    }
    public function clearScripts () 
    {
        $this->clearMemory($this->scripts, 'scripts');
    }
    public function clearStyles () 
    {
        $this->clearMemory($this->styles, 'styles');
    }
    public function restoreScripts ()
    {
        $this->restoreMemory($this->scripts, 'scripts');
    }
    public function restoreStyles ()
    {
        $this->restoreMemory($this->scripts, 'styles');
    }
    
    //---------------------------------------
    // PROTECTED METHODS
    //---------------------------------------
    
    /**
     * Operate on a script or style
     * Cook and save results. Comments are automatically removed.
     */
    protected function addScript ($path, $skipEmpty = false) 
    {
        $name = $this->arrayElement('filename', pathinfo($path));
        $raw = file_get_contents($path);
        $cooked = array(
            'head' => $this->getFileHeader($raw), 
            'body' => $this->moldOutput(';',
                $this->closureCompiler->getCompiledScript($path)
            )
        );
        if ($skipEmpty && $this->isEmptyString($cooked['body'])) {
            return;
        }
        $this->scripts[$name] = $cooked;
    }
    protected function addStyle ($path, $skipEmpty = false)
    {
        $name = $this->arrayElement('filename', pathinfo($path));
        $raw = file_get_contents($path);
        $cooked = array('head' => $this->getFileHeader($raw));
        if (strpos($raw, '@import') !== false) { // don't cook
            $cooked['body'] = str_replace($cooked['head'] . PHP_EOL, '', $raw);
            $cooked['type'] = self::PARENT_STYLE;
        } else {
            $cooked['body'] = $this->moldOutput('}',
                $this->cssCompressor->process($raw)
            );
        }
        if ($skipEmpty && $this->isEmptyString($cooked['body'])) {
            return;
        }
        $this->styles[$name] = $cooked;
    }
    /**
     * Parsing helpers
     */
    // Comment pattern matches multiline comments starting with /** and 
    // either ending with */ or **/ 
    protected function getFileHeader ($content) 
    {
        $matches = array();
        preg_match_all('/^[\/\s]?\*(?:[^\/]+[^\/]|\*+\/)$/m', $content, $matches);
        $result = '';
        foreach ($matches[0] as $match) {
            $result .= $match . PHP_EOL;
            if (strpos($match, '*/') !== false) { 
                break; // at the end of the file header block
            }
        }
        return $result;
    }
    protected function moldOutput ($delimiter, $content)
    {
        if ($this->maxLineLength == 0) {
            return $content;
        }
        $blocks = explode($delimiter, $content);
        $content = $line = '';
        foreach ($blocks as &$block) {
            if (strlen($line) > $this->maxLineLength) {
                $content .= $line . PHP_EOL;
                $line = '';
            }
            $line .= $block . $delimiter;
        }
        return trim($content);
    }
    protected function getScriptCalls ($callee = null) 
    {
        if (!isset($callee)) {
            throw new RuntimeException('Template cannot be empty');
            return;
        }
        if (is_file($callee)) {
            $contents = file_get_contents($callee);
            $matches = array(); preg_match_all('/(<script\b.+src="(?!http:\/\/)[^"]+\/)([^"]+)(.js".*<\/script>)/i', $contents, $matches);
            $this->scriptCalls[$callee] = $matches[2];
            $this->log("Getting contents of $callee"
                // , htmlentities($contents)
                ); 
            $this->log("Getting called scripts"
                // , $matches[2]
                );
            return true;
        } elseif (strpos($callee, $this->devStylePath) !== 0) { 
            $callee = $this->buildPath($this->devStylePath, $callee);
            if (!$this->getScriptCalls($callee)) {
                throw new RuntimeException('Template path is still invalid.');
                return false;
            } else {
                return true;
            }
        }
        return false;
    }
    /**
     * Path helpers
     */
    protected function checkScriptPath ()
    {
        if (!isset($this->prodScriptPath)) {
            if (isset($this->devScriptPath)) {
                $this->prodScriptPath = $this->devScriptPath;
            } else {
                throw new RuntimeException('Need a destination for production scripts');
                return;
            }
        }
    }
    protected function checkStylePath () 
    {
        if (!isset($this->prodStylePath)) {
            if (isset($this->devStylePath)) {
                $this->prodStylePath = $this->devStylePath;
            } else {
                throw new RuntimeException('Need a destination for production styles');
                return;
            }
        }
    }
    protected function buildFileName ($name, $extension)
    {
        $fullName = array($name);
        if ($this->timestamped) {
            $fullName[] = time();
        }
        $fullName[] = $extension;
        return implode('.', $fullName);
    }
    protected function clearMemory (&$property, $name = null, $save = true)
    {
        if (isset($property) && isset($name) && $save) {
            if (!array_key_exists($name, $this->store)) {
                $this->store[$name] = array();
            }
            $this->store[$name][] = $property;
            $this->log("Added memory store for $name");
        }
        $property = array();
        $this->log("Cleared $name");
    }
    protected function restoreMemory (&$property, $name)
    {
        if (!array_key_exists($name, $this->store)) {
            throw new RuntimeException('Store does not exist');
            return;
        }
        foreach ($this->store[$name] as $index => $store) {
            $property = array_merge($property, $store);
            $this->log("Restored memory store $index for $name");
        }
    }
}