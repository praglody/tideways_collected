<?php
/**
 * Created by PhpStorm.
 * User: zhudp
 * Date: 2019/5/29
 * Time: 2:02 PM
 */

# 采集的阈值，默认500毫秒以上才会采集
defined("XHPROF_PROFILE_BENCHMARK") || define("XHPROF_PROFILE_BENCHMARK", 500);

if (PHP_MAJOR_VERSION < 7) {
    return;
} elseif (!defined("XHPROF_PROFILE_COUNT") || !extension_loaded('mongodb')) {
    return;
} elseif (XHPROF_PROFILE_COUNT <= 0 || mt_rand(1, XHPROF_PROFILE_COUNT) != 1) {
    return;
}

if (extension_loaded('tideways')) {
    tideways_enable(TIDEWAYS_FLAGS_CPU | TIDEWAYS_FLAGS_MEMORY);
    tideways_span_create('sql');
} elseif (function_exists('xhprof_enable')) {
    if (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION > 4) {
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_NO_BUILTINS);
    } else {
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
    }
} elseif (extension_loaded('tideways_xhprof')) {
    tideways_xhprof_enable(
        TIDEWAYS_XHPROF_FLAGS_MEMORY |
        TIDEWAYS_XHPROF_FLAGS_MEMORY_MU |
        TIDEWAYS_XHPROF_FLAGS_MEMORY_PMU |
        TIDEWAYS_XHPROF_FLAGS_CPU
    );
} elseif (extension_loaded('uprofiler')) {
    uprofiler_enable(UPROFILER_FLAGS_CPU | UPROFILER_FLAGS_MEMORY);
} else {
    // 没有可用的扩展
    return;
}

if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
}

register_shutdown_function(function () {
    $time_usec = microtime(true);
    if (($time_usec - $_SERVER['REQUEST_TIME_FLOAT']) * 1000 < XHPROF_PROFILE_BENCHMARK) {
        return;
    }

    $data = array();
    if (extension_loaded('tideways')) {
        $data['profile'] = tideways_disable();
        $sqlData         = tideways_get_spans();
        $data['sql']     = array();
        if (isset($sqlData[1])) {
            foreach ($sqlData as $val) {
                if (isset($val['n']) && $val['n'] === 'sql' && isset($val['a']) && isset($val['a']['sql'])) {
                    $_time_tmp = (isset($val['b'][0]) && isset($val['e'][0])) ? ($val['e'][0] - $val['b'][0]) : 0;
                    if (!empty($val['a']['sql'])) {
                        $data['sql'][] = array(
                            'time' => $_time_tmp,
                            'sql'  => $val['a']['sql']
                        );
                    }
                }
            }
        }
    } elseif (function_exists('xhprof_enable')) {
        $data['profile'] = xhprof_disable();
    } elseif (extension_loaded('tideways_xhprof')) {
        $data['profile'] = tideways_xhprof_disable();
    } elseif (extension_loaded('uprofiler')) {
        $data['profile'] = uprofiler_disable();
    }

    // ignore_user_abort(true) allows your PHP script to continue executing, even if the user has terminated their request.
    // Further Reading: http://blog.preinheimer.com/index.php?/archives/248-When-does-a-user-abort.html
    // flush() asks PHP to send any data remaining in the output buffers. This is normally done when the script completes, but
    // since we're delaying that a bit by dealing with the xhprof stuff, we'll do it now to avoid making the user wait.
    ignore_user_abort(true);
    flush();

    $uri = array_key_exists('REQUEST_URI', $_SERVER)
        ? $_SERVER['REQUEST_URI']
        : null;
    if (empty($uri) && isset($_SERVER['argv'])) {
        $cmd = basename($_SERVER['argv'][0]);
        $uri = $cmd . ' ' . implode(' ', array_slice($_SERVER['argv'], 1));
    }

    $time           = array_key_exists('REQUEST_TIME', $_SERVER)
        ? $_SERVER['REQUEST_TIME']
        : time();
    $requestTs      = new \MongoDB\BSON\UTCDateTime($time * 1000);
    $requestTsMicro = new \MongoDB\BSON\UTCDateTime(floor($_SERVER['REQUEST_TIME_FLOAT'] * 1000));

    $simple_url   = explode("?", $uri);
    $data['meta'] = array(
        'url'              => $uri,
        'SERVER'           => $_SERVER,
        'get'              => $_GET,
        'env'              => $_ENV,
        'simple_url'       => $simple_url[0],
        'request_ts'       => $requestTs,
        'request_ts_micro' => $requestTsMicro,
        'request_date'     => date('Y-m-d', $time),
    );
    $data['_id']  = new \MongoDB\BSON\ObjectId();

    try {
        // 这里可以用自己的配置加载方式
        $conf = new Yaf_Config_Ini(ROOT_PATH . '/conf/mongodb.ini', "xhprof");
        $host        = $conf['host'];
        $db          = $conf['db'];
        $mongoManger = new \MongoDB\Driver\Manager("mongodb://" . $host);
        $mongoWrite  = new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY, 1000);
        $mongoBulk   = new \MongoDB\Driver\BulkWrite();
        $mongoBulk->insert($data);
        $mongoManger->executeBulkWrite($db . ".results", $mongoBulk, $mongoWrite);
    } catch (\Exception $e) {
        // 记录异常日志
        Ap_Log::error($e->getMessage());
    }
});
