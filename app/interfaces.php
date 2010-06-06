<?php if (!defined('DEPLOY_APP')) die('Illegal access');

interface ISingleton
{
    static function instance ();
    function destroy ();
}

interface IJsCompilerService 
{
    function getCompiledScript ($path); 
}

interface ICssCompressor
{
    function process ($raw);
}

interface IExtendedLanguage
{
    function arrayElement ($key, $array, $default = false);
    function isEmptyString ($string);
    function log ($string, $object = null);
}

interface IFileManager 
{
    function traverseDirectory ($path, $callbackInfo = null, 
                                $typesToKeep = array(), $typesToSkip = array(), 
                                $skipMeta = true, $dirsToSkip = array());
    function publishFile ($path, $name, $content);
    function buildPath ();
}

// loosely coupled script and style procedures
interface IAssetDeployer extends IFileManager
{
    function addScripts ($path = '');
    function addStyles ($path = '');
    function publishScripts ($callee = null, $name = null);
    function publishStyles ($callee = null, $name = null);
    function updateScriptCalls ($callee = null);
    function updateStyleCalls ($callee = null);
    function clearScripts ();
    function clearStyles ();
    function restoreScripts ();
    function restoreStyles ();
}