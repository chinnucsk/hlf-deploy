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
 * @todo full timestamp integration
 * @todo twitter & facebook notifications
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

    public $developmentUrl;
    public $productionUrl;
    public $stagingUrl;
    
    public $forceDebug;

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

    protected $appStage;

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
        $this->forceDebug = false;
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
                if ($type = pathinfo($name, PATHINFO_EXTENSION)) {
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
                } elseif (is_file($path . $name)) {
                    $this->log("Tallying and acting on file ${path}${name}");
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
    public function diffPaths ($foil, $path)
    {
        return trim(str_replace($foil, '', $path), $this->ds);
    }
    public function publishFile ($path, $name, $content) 
    {
        $path = rtrim($path, $this->ds) . $this->ds;
        if (!is_dir($path)) {
            $this->log("Creating new directory $path");
            mkdir($path, 0775, true);
        }
        file_put_contents($path . $name, $content);
        $this->log("Published file ${path}${name}"
            // , $content
            );
    }
    public function backupFile ($source, $override = true, $destination = null)
    {
        if (!isset($destination)) {
            $destination = $this->buildPath(pathinfo($source, PATHINFO_DIRNAME), '.backup');
            if (!is_dir($destination)) {
                $this->log("Creating new directory $destination");
                mkdir($destination, 0775, true);
            }
            $destination = $this->buildPath($destination, pathinfo($source, PATHINFO_BASENAME));
        }
        if (!is_file($destination) || $override) { // can write
            if (!is_file($destination)) {
                $success = copy($source, $destination . '.pristine');
                $this->log("Created pristine copy of file $source");
            }
            $success = copy($source, $destination);
            $this->log("Backed up file $source to $destination");
        } else {
            $success = true;
            $this->log("Backup at $destination already exists");
        }
        return $success;
    }
    public function restoreBackupFile ($source, $override = true, $pristine = false, 
                                       $destination = null)
    {
        if (!isset($destination)) {
            $destination = $this->buildPath(pathinfo($source, PATHINFO_DIRNAME), '.backup', pathinfo($source, PATHINFO_BASENAME));
            if ($pristine) {
                $destination .= '.pristine';
            }
            if (!is_file($destination)) {
                return false;
            }
        }
        if (!is_file($source) || $override) {
            $success = copy($destination, $source);
            $this->log("Restored backup $destination to $source");
        } else {
            $success = true;
            $this->log("File $source already exists and overriding is off");
        }
        return $success;
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
    public function areSet () {
        foreach (func_get_args() as $arg) {
            if (!isset($arg)) {
                return false;
            }
        }
        return true;
    }
    public function log ($string, $object = null) 
    { 
        print "<pre>$string";
        if (isset($object) && $this->isDev()) {
            print ':' . str_repeat(PHP_EOL, 2);
            var_export($object);
        } 
        print '</pre><br/>';
    }
    
    // APPLICATION LIFECYCLE METHODS
    
    public function isDev () {
        return ($this->getAppStage() === self::DEVELOPMENT);
    }
    public function isProd () {
        return ($this->getAppStage() === self::PRODUCTION);
    }
    public function isStage () {
        return ($this->getAppStage() === self::STAGING);        
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
        if (isset($callee)) {
            $this->getScriptCalls($callee);
            $this->log('Publishing these scripts', $this->scriptCalls[$callee]);
            $output = '';
            foreach ($this->scriptCalls[$callee] as $scriptName) {
                $script = $this->arrayElement($scriptName, $this->scripts);
                if (!$script) {
                    continue;
                }
                $output .= $script['head'] . PHP_EOL . $script['body'] . PHP_EOL;   
            }
            $fullName = $this->buildFileName((isset($name) ? $name : 'production'), 'js');
            $this->publishFile($this->prodScriptPath, $fullName, $output);
        } else {
            $this->log('Publishing these scripts', array_keys($this->scripts));
            foreach ($this->scripts as $scriptName => $script) 
            {
                $fullName = $this->buildFileName($scriptName, 'min.js');
                $output = $script['head'] . PHP_EOL . $script['body'] . PHP_EOL;
                $this->publishFile($this->prodScriptPath, $fullName, $output);
                $this->log($output);
            }
        }
    }
    public function publishStyles ($callee = null, $name = null)
    {
        $this->checkStylePath();
        if ($this->mode === self::PROGRESSIVE_ENHANCEMENT) {
            $this->log('Publishing these styles', array_keys($this->styles));
            foreach ($this->styles as $styleName => &$style)
            {
                $fullName = $this->buildFileName($styleName, 'min.css');
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
            $this->log('Publishing these styles', array_keys($this->styles));
            $output = '';
            foreach ($this->styles as $style) 
            {
                $output .= $style['head'] . PHP_EOL . $style['body'] . PHP_EOL;
            }
            $fullName = $this->buildFileName((isset($name) ? $name : 'production'), 'css');
            $this->publishFile($this->prodStylePath, $fullName, $output);
        }
    }
    /**
     * Update dependent page
     * 
     */
    public function updateScriptCalls ($callee = null, $revert = false, $name = null)
    {
        if (!isset($callee)) {
            throw new RuntimeException('Template cannot be empty');
            return;
        }
        if (is_file($callee)) {
            if ($revert) {
                $this->restoreBackupFile($callee, true, true);
                return true;
            }
            $this->backupFile($callee);
            $contents = file_get_contents($callee);
            $included = false;
            $subPath = $this->diffPaths($this->devScriptPath, $this->prodScriptPath);
            foreach ($this->scripts as $scriptName => $script) 
            {
                if (in_array($scriptName, $this->scriptCalls[$callee])) {
                    $scriptName .= '.js';
                    $pattern = '/^\s*(<script\b.+src="(?!http:\/\/)[^"]+\/)(' 
                        . preg_quote($scriptName) 
                        . ')(".*\n)/im';
                    if (!$included) {
                        $replacement = "\t" . '$1' . "$subPath/" . (isset($name) ? $name : 'production') . '.js' . '$3';
                        $included = true;
                    } else {
                        $replacement = '';
                    }
                }
                if ($this->areSet($pattern, $replacement, $contents)) {
                    $contents = preg_replace($pattern, $replacement, $contents);
                }
            }
            file_put_contents($callee, $contents);
            $this->log("Updated script calls in $callee"
                , htmlentities($contents) // #RM
                );
            return true;
        } elseif (strpos($callee, $this->devScriptPath) !== 0) { 
            $callee = $this->buildPath($this->devScriptPath, $callee);
            if (!$this->updateScriptCalls($callee, $revert, $name)) {
                throw new RuntimeException('Template path is still invalid.');
            } else {
                return true;
            }
        } 
        return false;
    }
    public function updateStyleCalls ($callee = null, $revert = false, $name = null)
    {
        if (!isset($callee)) {
            throw new RuntimeException('Template cannot be empty');
            return;
        } 
        if (is_file($callee)) {
            $this->backupFile($callee);
            $contents = file_get_contents($callee);
            $subPath = $this->diffPaths($this->devStylePath, $this->prodStylePath);
            if ($this->mode === self::PROGRESSIVE_ENHANCEMENT) {
                foreach ($this->styles as $styleName => $style) {
                    $styleName .= '.css';
                    $pattern = '/(<link\b.+href="(?!http:\/\/)[^"]+\/)(' 
                        . preg_quote($styleName) 
                        . ')(".*\n)/im';
                    $replacement = '$1' . str_replace(array('.css', '.min.css'), '.min.css', "$subPath/$styleName") . '$3';
                    $contents = preg_replace($pattern, $replacement, $contents);
                }
            } else {
                $included = false;
                foreach ($this->styles as $styleName => $style) {
                    if (in_array($styleName, $this->styleCalls[$callee])) {
                        $styleName .= '.css';
                        $pattern = '/(<link\b.+href="(?!http:\/\/)[^"]+\/)(' 
                            . preg_quote($styleName) 
                            . ')(".*\n)/im';
                        if (!$included) {
                            $replacement = '$1' . "$subPath/" . (isset($name) ? $name : 'production') . '.css' . '$3';
                            $included = true;
                        } else {
                            $replacement = '';
                        }
                    }
                    if ($this->areSet($pattern, $replacement, $contents)) {
                        $contents = preg_replace($pattern, $replacement, $contents);
                    }
                }
            }
            file_put_contents($callee, $contents);
            $this->log("Updated style calls in $callee"
                , htmlentities($contents)
                );
            return true;
        } elseif (strpos($callee, $this->devStylePath) !== 0) { 
            $callee = $this->buildPath($this->devStylePath, $callee);
            if (!$this->updateStyleCalls($callee, $revert, $name)) {
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
        $raw = file_get_contents($path);
        $cooked = array(
            'head' => $this->getFileHeader($raw), 
            'body' => (($this->isProd() || $this->isStage()) 
                ? $this->moldOutput('', $this->closureCompiler->getCompiledScript($path))
                : $raw // so we don't overload the service and get banned
            )
        );
        if ($skipEmpty && $this->isEmptyString($cooked['body'])) {
            return;
        }
        $this->scripts[pathinfo($path, PATHINFO_FILENAME)] = $cooked;
    }
    protected function addStyle ($path, $skipEmpty = false)
    {
        $raw = file_get_contents($path);
        $cooked = array('head' => $this->getFileHeader($raw));
        if (strpos($raw, '@import') !== false) { // don't cook
            $cooked['body'] = str_replace($cooked['head'] . PHP_EOL, '', $raw);
            $cooked['type'] = self::PARENT_STYLE;
        } else {
            $cooked['body'] = $this->moldOutput('}', $this->cssCompressor->process($raw));
        }
        if ($skipEmpty && $this->isEmptyString($cooked['body'])) {
            return;
        }
        $this->styles[pathinfo($path, PATHINFO_FILENAME)] = $cooked;
    }
    /**
     * Parsing helpers
     */
    // Comment pattern matches multiline comments starting with /** and 
    // either ending with */ or **/ 
    protected function getFileHeader ($content) 
    {
        $matches = array(); preg_match_all('/\/\*([^*]|[\r\n]|(\*+([^*\/]|[\r\n])))*\*+\//m', $content, $matches);
        $result = '';
        foreach ($matches[0] as $match) {
            $result .= $match . PHP_EOL;
            if (strpos($match, '*/') !== false) { 
                break; // at the end of the file header block
            }
        }
        // $this->log('File header', $result);
        return $result;
    }
    protected function moldOutput ($delimiter, $content)
    {
        if ($this->maxLineLength == 0 || empty($delimiter)) {
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
                , htmlentities($contents)
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
        $fullName = array(str_replace($extension, '', $name));
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
    /**
     * Application lifecycle helpers
     */
    protected function getAppStage () 
    {
        if (!isset($this->appStage)) {
            switch (true) {
                case (strpos($_SERVER['HTTP_HOST'], $this->developmentUrl) !== false):
                    $this->appStage = self::DEVELOPMENT;
                    break;
                case (strpos($_SERVER['HTTP_HOST'], $this->productionUrl) !== false):
                    $this->appStage = self::PRODUCTION;
                    break;
                case (strpos($_SERVER['HTTP_HOST'], $this->stagingUrl) !== false):
                    $this->appStage = self::STAGING;
                    break;
            }
        }
        return $this->forceDebug ? self::DEVELOPMENT : $this->appStage;
    }
}