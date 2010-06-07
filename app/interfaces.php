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
    function arrayElement ($key, $array, $default);
    function isEmptyString ($string);
    function log ($string, $object);
}

interface IFileManager 
{
    function traverseDirectory ($path, $callbackInfo, 
                                $typesToKeep, $typesToSkip, 
                                $skipMeta, $dirsToSkip);
    function buildPath (); // dynamic
    function diffPaths ($foil, $path);
    function publishFile ($path, $name, $content);
    function backupFile ($source, $override, $destination);
    function restoreBackupFile ($source, $override, $pristine, $destination);
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
    function publishScripts ($callee, $name);
    function publishStyles ($callee, $name);
    function updateScriptCalls ($callee, $revert, $name);
    function updateStyleCalls ($callee, $revert, $name);
    function clearScripts ();
    function clearStyles ();
    function restoreScripts ();
    function restoreStyles ();
}