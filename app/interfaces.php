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
}

interface ITemplateManipulator
{
    function replaceNodes ();
}

interface IFileManager 
{
    function traverseDirectory ($path, $callbackInfo = false, 
                                $typesToKeep = array(), $typesToSkip = array(), 
                                $skipMeta = true, $dirsToSkip = array());
    function publishFile ($path, $name, $content);
    function buildPath ();
}

// loosely coupled script and style procedures
interface IAssetDeployer extends ITemplateManipulator, IFileManager
{
    function addScripts ($path = '');
    function addStyles ($path = '');
    function publishScripts ();
    function publishStyles ();
    function updateScriptCalls ($path = '');
    function updateStyleCalls ($path = '');
}