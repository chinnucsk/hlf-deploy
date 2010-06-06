<?php define('DEPLOY_APP', 9999);

require 'app/AssetDeployer.php';

import_request_variables('g', 'query_var_');

echo '<pre>'; 

$d = AssetDeployer::instance();

//---------------------------------------
// DEPLOY CSS
//---------------------------------------
/*
$d->devStylePath = '/Users/penguin/Sites/env.lamp/hlf-main-ndxz/ndxz-studio/site/pengxwang';
$d->prodStylePath = $d->buildPath($d->devStylePath, 'production');
$d->addStyles();
$d->publishStyles();

// TODO add lib css

if (isset($query_var_revert) && $query_var_revert) {
    $d->updateStyleCalls('test-page.php', true);
} else {
    $d->updateStyleCalls('test-page.php');
}
*/
//---------------------------------------
// DEPLOY JS
//---------------------------------------
$d->devScriptPath = '/Users/penguin/Sites/env.lamp/hlf-main-ndxz/ndxz-studio/site/js';
$d->prodScriptPath = $d->buildPath($d->devScriptPath, 'production');
$d->addScripts();
$d->publishScripts();

$d->clearScripts();
$d->devScriptPath = '/Users/penguin/Sites/env.lamp/hlf-main-ndxz/ndxz-studio/site/pengxwang';
$d->prodScriptPath = $d->buildPath($d->devScriptPath, 'production');
$d->addScripts();
$d->publishScripts();

$d->restoreScripts();
$d->publishScripts(dirname(__FILE__) . '/test-page.php');

if (isset($query_var_revert) && $query_var_revert) {
    $d->updateScriptCalls(dirname(__FILE__) . '/test-page.php', true);
} else {
    $d->updateScriptCalls(dirname(__FILE__) . '/test-page.php');
}

echo '</pre>';