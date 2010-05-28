<?php define('DEPLOY_APP', 9999);

require 'app/AssetDeployer.php';

echo '<pre>'; 

$d = AssetDeployer::instance();
// $d->devScriptPath = realpath(dirname(__FILE__));
// $d->devStylePath = realpath(dirname(__FILE__)) . '/api';
// $d->addScripts('feed.posterous.js');
// $d->addStyles('feed.css');
// $d->publishScripts();

$d->devStylePath = '/Users/penguin/Sites/env.lamp/hlf-main-ndxz/ndxz-studio/site/pengxwang';
$d->prodStylePath = $d->buildPath($d->devStylePath, 'production');
$d->addStyles();
$d->publishStyles();
// $d->updateStyleCalls($d->buildPath($d->devStylePath, 'index.php'), true);
$d->updateStyleCalls($d->buildPath($d->devStylePath, 'index.php'));

echo '</pre>';