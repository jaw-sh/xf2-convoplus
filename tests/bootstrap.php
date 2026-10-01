<?php

// XF's exception logger requires a valid address even in a CLI test process.
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Set HB_AUTO_PRUNE_UNIT_ONLY=1 to run pure tests without opening the XF database.
$xfRoot = getenv('XF_ROOT') ?: dirname(__DIR__, 5);
$xfLoaded = false;
if (getenv('HB_AUTO_PRUNE_UNIT_ONLY') !== '1' && is_file($xfRoot . '/src/XF.php'))
{
    require_once $xfRoot . '/src/XF.php';
    \XF::start($xfRoot);
    \XF::setupApp('XF\\Cli\\App');
    $xfLoaded = true;
}
define('HAPPYBOARD_CONVO_PLUS_TEST_XF', $xfLoaded);

$happyBoardRoot = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($happyBoardRoot): void
{
    $prefix = 'HappyBoard\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0)
    {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $parts = explode('\\', $relative);
    if (($parts[1] ?? '') === 'Tests')
    {
        $parts[1] = 'tests';
    }
    $file = $happyBoardRoot . '/' . implode('/', $parts) . '.php';
    if (is_file($file))
    {
        require_once $file;
    }
});
