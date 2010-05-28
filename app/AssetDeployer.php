<?php if (!defined('DEPLOY_APP')) die('Illegal access');

require 'interfaces.php';
require 'ClosureCompilerService.php';
require 'MinifyCssCompressor.php';

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
    protected $rawScripts;
    protected $cookedScripts;
    protected $scriptHeaders;

    protected $cssCompressor;
    protected $rawStyles;
    protected $cookedStyles;
    protected $styleHeaders;
    
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
        $this->closureCompiler = ClosureCompilerService::instance();
        $this->rawScripts = array();
        $this->cookedScripts = array();
        $this->cssCompressor = MinifyCssCompressor::instance();
        $this->rawStyles = array();
        $this->cookedStyles = array();
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
    public function traverseDirectory ($path, $callbackInfo = false, 
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
                    $result[] = call_user_func($callbackInfo, $path . $name);
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
    
    // ASSET DEPLOYER METHODS
    
    /**
     * Populate content stores
     */
    public function addScripts ($path = '')
    {
        if (is_file($path)) {
            $this->addScript($path);
            // var_export($this->cookedScripts);
            return true;
        } elseif (is_dir($path)) {
            $this->traverseDirectory($path, array($this, 'addScript'), array('js'), 
                                     array(), true, array('production'));
            // var_export($this->cookedScripts);
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
            // var_export($this->cookedStyles);
            return true;
        } elseif (is_dir($path)) {
            $this->traverseDirectory($path, array($this, 'addStyle'), array('css'), 
                                     array(), true, array('production'));
            var_export($this->cookedStyles);
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
     */
    public function publishScripts ($name = '') 
    {
        $this->checkScriptPath();
        $fullName = $this->buildFileName((!empty($name) ? $name : 'production'), 'js');
        $this->combineIntoOutput($this->cookedScript);
        $output = '';
        foreach ($this->cookedScripts as $script) 
        {
            $output .= $script['head'] . PHP_EOL;
            $output .= $script['body'] . PHP_EOL;
        }
        $this->publishFile($this->prodScriptPath, $fullName, $output);
    }
    public function publishStyles ($name = '')
    {
        $this->checkStylePath();
        if ($this->mode === self::PROGRESSIVE_ENHANCEMENT) {
            foreach ($this->cookedStyles as $name => &$style)
            {
                $style['publishName'] = $fullName = $this->buildFileName("$name.min", 'css');
                $output = $style['head'] . PHP_EOL;
                if ($this->isEmptyString(trim($style['body']))) { // TODO - refine this patch
                    continue;
                }
                if ($this->arrayElement('type', $style) === self::PARENT_STYLE) {
                    $style['body'] = preg_replace(
                        '/(@import.*)((?<!\.min)\.css)(.*)$/',
                        '$1.min.css$2', $style['body']
                    );
                }
                $output .= $style['body'] . PHP_EOL;
                $this->publishFile($this->prodStylePath, $fullName, $output);
            }
        } else {
            $fullName = $this->buildFileName((!empty($name) ? $name : 'production'), 'css');
            $output = '';
            foreach ($this->cookedStyles as $style) 
            {
                $output .= $style['head'] . PHP_EOL;
                $output .= $style['body'] . PHP_EOL;
            }
            $this->publishFile($this->prodStylePath, $fullName, $output);
        }
    }
    /**
     * Update dependent pages
     */
    public function updateScriptCalls ($path = '', $revert = false)
    {
        
    }
    public function updateStyleCalls ($path = '', $revert = false)
    {
        if (empty($path)) {
            throw new RuntimeException('Template cannot be empty');
            return;
        }
        if (is_file($path)) {
            $contents = file_get_contents($path);
            if ($this->mode === self::PROGRESSIVE_ENHANCEMENT) {
                foreach ($this->cookedStyles as $name => $style) {
                    if ($revert) {
                        $pattern = '/(<link\b.+href="[^"]+)(' . preg_quote($style['publishName']) . ')(".*[^%]>)/';
                        $replacement = '$1' . str_replace('.min.css', '.css', $style['publishName']) . '$3';
                    } else {
                        $name .= '.css';
                        $pattern = '/(<link\b.+href="[^"]+)(' . preg_quote($name) . ')(".*[^%]>)/';
                        $replacement = '$1' . str_replace('.css', '.min.css', $name) . '$3';
                    }
                    $contents = preg_replace($pattern, $replacement, $contents);
                }
            } else {
                
            }
            // var_export(htmlentities($contents));
            file_put_contents($path, $contents);
            return true;
        } elseif (strpos($path, $this->devStylePath) !== 0) {
            $path = $this->buildPath($this->devStylePath, $path);
            if (!$this->updateStyleCalls($path, $revert)) {
                throw new RuntimeException('Template path is still invalid.');
            } else {
                return true;
            }
        } 
        return false;
    }

    //---------------------------------------
    // PROTECTED METHODS
    //---------------------------------------
        
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
        $this->rawScripts[$name] = $raw;
        $this->cookedScripts[$name] = $cooked;
    }
    protected function addStyle ($path, $skipEmpty = false)
    {
        $name = $this->arrayElement('filename', pathinfo($path));
        $raw = file_get_contents($path);
        $cooked = array('head' => $this->getFileHeader($raw));
        if (strpos($raw, '@import') !== false) {
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
        $this->rawStyles[$name] = $raw;
        $this->cookedStyles[$name] = $cooked;
    }
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
}