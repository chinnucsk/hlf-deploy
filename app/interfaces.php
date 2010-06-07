<?php if (!defined('DEPLOY_APP')) die('Illegal access');

interface ISingleton
{
    static function instance ();
    function destroy ();
}

interface IJsCompilerService 
{
    function getCompiledScript ($path); 
    function getScriptWarnings ($path); 
    function getScriptErrors ($path); 
    function getScriptStats ($path); 
}

interface ICssCompressor
{
    function process ($raw);
}

interface IExtendedLanguage
{
    function arrayElement ($key, $array, $default = false);
    function isEmptyString ($string);
    function areSet (); // dynamic
    function log ($string, $object = null);
}

interface IFileManager 
{
    function traverseDirectory ($path, $callbackInfo = null, 
                                $typesToKeep = array(), $typesToSkip = array(), 
                                $skipMeta = true, $dirsToSkip = array());
    function buildPath (); // dynamic
    function diffPaths ($foil, $path);
    function publishFile ($path, $name, $content);
    function backupFile ($source, $override = true, $destination = null);
    function restoreBackupFile ($source, $override = true, $pristine = false, 
                                $destination = null);
}

interface IApplicationLifecycle
{
    const DEVELOPMENT = 0;
    const PRODUCTION = 1;
    const STAGING = 2;
    function isDev ();
    function isProd ();
    function isStage ();
}

// loosely coupled script and style procedures
interface IAssetDeployer extends IFileManager, IApplicationLifecycle
{
    const PROGRESSIVE_ENHANCEMENT = 'progressive enhancement deployer mode';
    const PARENT_STYLE = 'parent style type';

    function addScripts ($path = '');
    function addStyles ($path = '');
    function publishScripts ($callee = null, $name = null);
    function publishStyles ($callee = null, $name = null);
    function updateScriptCalls ($callee = null, $revert = false, $name = null);
    function updateStyleCalls ($callee = null, $revert = false, $name = null);
    function clearScripts ();
    function clearStyles ();
    function restoreScripts ();
    function restoreStyles ();
}