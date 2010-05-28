<?php if (!defined('DEPLOY_APP')) die('Illegal access');

/**
 * Minify and return JavaScript using Closure Compiler API and CURL
 * Simple class with a full-featured implementation of the API
 * @see http://code.google.com/intl/en_US/closure/compiler/docs/api-ref.html
 * @author Peng Wang <peng@pengxwang.com>
 * @version 1
 */

class ClosureCompilerService implements IJsCompilerService, ISingleton
{    
    //---------------------------------------
    // CLASS CONSTANTS, PUBLIC VARIABLES
    //---------------------------------------
    const URL = 'http://closure-compiler.appspot.com/compile';

    public $compilationLevel;
    const SIMPLE = 'SIMPLE_OPTIMIZATIONS';
    const ADVANCED = 'ADVANCED_OPTIMIZATIONS';
    const WHITESPACE = 'WHITESPACE_ONLY';

    public $outputFormat;
    const TEXT = 'text';
    const XML = 'xml';
    const JSON = 'json';

    public $outputInfo;
    const CODE = 'compiled_code';
    const WARNINGS = 'warnings';
    const ERRORS = 'errors';
    const STATS = 'statistics';
    
    // optional
    public $warningLevel;
    const DEFAULT_WARNING = 'DEFAULT'; // DEFAULT is reserved
    const QUIET = 'QUIET';
    const VERBOSE = 'VERBOSE';

    public $formatting;
    const DELIMITED = 'print_input_delimiter';
    
    public $excludeDefaultExternals;
    public $useClosureLibrary;
    
    // optional & advanced
    public $jsExternals;
    public $urlExternals;

    // not implemented out of preference: 
    // - output_file_name
    // - formatting: pretty_print
    
    public $printReports; 
    
    //---------------------------------------
    // PROTECTED VARIABLES
    //---------------------------------------
    protected static $instance; 
    protected static $optional = array(
        'warning_level' => 'warningLevel',
        'formatting' => 'formatting',
        'exclude_default_externs' => 'excludeDefaultExternals',
        'use_closure_library' => 'useClosureLibrary',
        'js_externs' => 'jsExternals',
        'externs_url' => 'urlExternals'
    );
    protected static $advanced = array('js_externs', 'externs_url');
    protected $handle;
    
    //---------------------------------------
    // CONSTRUCTOR / DESTRUCTOR
    //---------------------------------------
    protected function __construct () 
    {
        if (isset(self::$instance)) {
            throw new RuntimeException('Instance of ClosureCompilerService already exists.');
            return;
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP CURL support must be enabled to use the Google Closure Compiler service');
        }
        $this->outputInfo = self::CODE;
        $this->outputFormat = self::TEXT;
        $this->compilationLevel = self::SIMPLE;
        // setup curl
        $this->handle = curl_init(self::URL); 
        curl_setopt($this->handle, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->handle, CURLOPT_POST, 1);
        // misc
        $this->printReports = false;
    }
    protected function __clone () {}
    public static function instance () 
    {
        if (!isset(self::$instance)) {
            self::$instance = new ClosureCompilerService();
        }
        return self::$instance;
    }
    public function destroy () 
    {
        curl_close($this->handle);
        if (!isset(self::$instance)) {
            return;
        }
        unset(self::$instance);
    }
    
    //---------------------------------------
    // GETTER / SETTERS
    //---------------------------------------
    public function __set ($name, $value) {
        // TODO only allow constants
    }
    //---------------------------------------
    // PUBLIC METHODS
    //---------------------------------------
    public function getCompiledScript ($path)
    {
        // $params, $compiled
        // setup 
        $params = $this->buildServiceOptions();
        if (strpos($path, 'http://') !== false) {
            $params['code_url'] = $path;
        } else {
            $params['js_code'] = file_get_contents($path);
            if ($params['js_code'] === false) {
                $params['js_code'] = file_get_contents($path, true);
                if ($params['js_code'] === false) {
                    throw new RuntimeException("File at $path not be read.");
                }
            }
        }
        curl_setopt($this->handle, CURLOPT_POSTFIELDS, http_build_query($params));
        // execute
        $compiled = curl_exec($this->handle);
        $this->handleRequestErrors();
        // report
        if ($this->printReports === true) {
            var_dump(array(
                'params' => $params,
                'compiled' => $compiled // /array
            ));
        }
        return $compiled;
    }
    public function getScriptWarnings ()
    {
        $this->outputInfo = self::WARNINGS;
        return $this->compileScript();
    }
    public function getScriptErrors () 
    {
        $this->outputInfo = self::ERRORS;
        return $this->compileScript();
    }
    public function getScriptStats ()
    {
        $this->outputInfo = self::STATS;
        return $this->compileScript();
    }
    //---------------------------------------
    // PROTECTED METHODS
    //---------------------------------------
    protected function buildServiceOptions ()
    {
        $options = array(
            'compilation_level' => $this->compilationLevel,
            'output_format' => $this->outputFormat,
            'output_info' => $this->outputInfo,
        );
        foreach (self::$optional as $name => $property) 
        {
            if (isset($this->{$property})) {
                if (in_array($name, self::$advanced) 
                    && $this->compilationLevel != self::ADVANCED) 
                {
                    continue; 
                }
                $options[$name] = $this->{$property};
            }
        }
        return $options;
    }
    protected function handleRequestErrors () 
    {
        $httpCode = curl_getinfo($this->handle, CURLINFO_HTTP_CODE);
        if ($httpCode != 200) {
            switch ($httpCode) {
                // TODO
            }
        }        
    }
}