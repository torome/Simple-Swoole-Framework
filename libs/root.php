<?php
/**
 * 框架总纲类
 * User: Dean.Lee
 * Date: 16/9/12
 */
//框架目录
defined('__ROOT__') or define('__ROOT__', realpath('./') . '/');
defined('LIB_PATH') or define('LIB_PATH', __DIR__ . '/');
defined('__APP__') or define('__APP__', 'apps');
//CLI命令
if(isset($argv[1]) && in_array($argv[1], ['start', 'restart', 'status', 'stop', 'reload']))define('CLI_COMMAND', $argv[1]);
else define('CLI_COMMAND', 'start');
//配置文件后缀
define('INI_EXT', '.ini.php');
//类库文件后缀
define('CLS_EXT', '.class.php');
//函数文件后缀
define('FUN_EXT', '.fun.php');

Class Root
{
    //swoole对象
    Static Public $serv = null;

    //进程对象
    Static Public $worker = null;

    //用户对象
    Static Public $user = null;

    //任务配置
    Static Public $tasks = [];

    //长连接通道
    Static Public $clients = [];

    //配置文件
    Static Public $conf = [];

    //所有类库文件的地图
    Static Public $map = [];

    //关联模型临时数据表
    Static Public $tmpTables = [];

    /**
     * 主框架运行
     */
    Static Public function run()
    {
        define('APP_PATH', __ROOT__ . __APP__ .'/');
        //创建应用场景
        if(!is_dir(APP_PATH)){
            //读取目录结构
            $xml = simplexml_load_file(LIB_PATH . 'ini/app.xml', null, LIBXML_NOCDATA);
            mkdir(APP_PATH, 0777) or die('目录没有可写权限!');
            chmod(APP_PATH, 0777);
            self::create($xml, APP_PATH);
        }
        //应用公共文件目录
        define('COMMON_PATH', APP_PATH . 'common/');
        //应用运行文件目录
        define('RUNTIME_PATH', APP_PATH . 'runtime/');
        //缓存目录路径
        define('TMP_PATH', RUNTIME_PATH . 'tmp/');
        //日志目录路径
        define('LOG_PATH', RUNTIME_PATH . 'log/');

        $command = 'Root::' . CLI_COMMAND;
        $command();
    }

    /**
     * 启动框架
     */
    Static Private function start()
    {
        if(is_file(TMP_PATH . 'server.pid')){
            $pid = @file_get_contents(TMP_PATH . 'server.pid');
            if($pid && swoole_process::kill($pid, 0))die("Framework has been started!" . PHP_EOL);
        }

        echo "Framework Starting...", PHP_EOL;
        //加载函数库
        self::loadUtil();
        //加载框架类库
        self::loadClass();
        //加载配置文件
        self::$conf = self::loadConf();
        //检测端口是否可用
        if(!checkPort(self::$conf['SERVER']['ip'], self::$conf['SERVER']['port'])){
            die('Port is occupied!' . PHP_EOL . "Starting Failed!" . PHP_EOL);
        }
        //模板文件后缀
        define('TPL_EXT', C('APPS.tpl_ext'));
        date_default_timezone_set(self::$conf['TIMEZONE']);

        //清空缓存
        \Root\Cache::clear(true);
        unlink(TMP_PATH . 'running.tmp');

        //启动异常处理和控制台
        \Root\Console::load();

        //创建内存表
        if(\Root\Table::create(C('MEMORY_TABLE')))
            echo "Memory table creation finish!", PHP_EOL;
        else {
            echo "Memory table creation failed!", PHP_EOL, "Starting Failed!", PHP_EOL;
            return;
        }
        //创建定时器
        \Root\Timer::create();

        $conf = self::$conf['SERVER'];
        $setup = [
            'pid_file' => TMP_PATH . 'server.pid',
            'reactor_num' => $conf['reactor_num'],
            'worker_num' => $conf['worker_num'],
            'backlog' => $conf['backlog'],
            'open_tcp_nodelay' => true,
            'max_request' => $conf['max_request'],
            'dispatch_mode' => 1
        ];
        self::$tasks = C('TASK')?:[];
        if(!empty(self::$tasks)){
            $task_num = 0;
            foreach(self::$tasks as $name => $task){
                $num = isset($task['process_num']) && $task['process_num'] > 0 ? $task['process_num'] : 1;
                self::$tasks[$name]['ids'] = [];
                for($i=$task_num; $i<$task_num+$num; $i++){
                    self::$tasks[$name]['ids'][] = $i;
                }
                $task_num += $num;
            }
            $setup['task_worker_num'] = $task_num;
        }
        if($conf['daemonize']){
            $setup['daemonize'] = true;
            $setup['log_file'] = TMP_PATH . 'running.tmp';
        }
        if(C('WEBSOCKET.is_enable')){
            self::$serv = Root\Websocket::create($conf['ip'], $conf['port']);
            $setup['heartbeat_idle_time'] = C('WEBSOCKET.heartbeat_idle_time');
            $setup['heartbeat_check_interval'] = C('WEBSOCKET.heartbeat_check_interval');
        }else{
            self::$serv = new swoole_http_server($conf['ip'], $conf['port']) or die('Swoole Starting Failed!' . PHP_EOL);
        }

        self::$serv->set($setup);

        self::$serv->on('start', 'Root\Http::start');
        self::$serv->on('managerstart', 'Root\Http::managerStart');

        //设置工作进程启动回调
        self::$serv->on('workerstart', 'Root\Worker::start');

        //设置进程间管道通信回调
        self::$serv->on('pipemessage', 'Root\Worker::pipeMessage');

        //设置HTTP请求回调
        self::$serv->on('request', 'Root\Http::request');

        //设置任务回调
        if(!empty(self::$tasks)){
            self::$serv->on('task', 'Root\Task::start');
            self::$serv->on('finish', 'Root\Task::finish');
        }

        self::$serv->start();
    }

    /**
     * 结束框架
     */
    Static Public function stop()
    {
        $pid = @file_get_contents(TMP_PATH . 'server.pid');
        if($pid){
            if(\swoole_process::kill($pid, 0))\swoole_process::kill($pid, 15);
            foreach(glob(TMP_PATH . '*.pid') as $filename){
                $pid = @file_get_contents($filename);
                if(\swoole_process::kill($pid, 0))\swoole_process::kill($pid, 9);
                @unlink($filename);
            }
            die('Stop of Framework Success!' . PHP_EOL);
        }
        die('Framework not started!' . PHP_EOL);
    }

    /**
     * 框架运行状态
     */
    Static Public function status()
    {
        $pid = @file_get_contents(TMP_PATH . 'server.pid');
        $rs = 0;
        if($pid)$rs = \swoole_process::kill($pid, 0);
        if($rs){
            echo "Framework is running..", PHP_EOL, PHP_EOL;
            \swoole_process::kill($pid, SIGUSR1);
            $i = 0;
            while(!file_exists(TMP_PATH . 'status.info')){
                usleep(100000);
                if(++ $i > 100){
                    die('无法获取进程状态!' . PHP_EOL);
                }
            }
            echo @file_get_contents(TMP_PATH . 'status.info') . PHP_EOL;
            @unlink(TMP_PATH . 'status.info');
            exit;
        }else die("Framework not started!" . PHP_EOL);
    }

    /**
     * 重启框架
     */
    Static Public function restart()
    {
        $pid = @file_get_contents(TMP_PATH . 'server.pid');
        if($pid){
            if(swoole_process::kill($pid, 0))swoole_process::kill($pid, 15);
            foreach(glob(TMP_PATH . '*.pid') as $filename){
                $pid = @file_get_contents($filename);
                if(\swoole_process::kill($pid, 0))\swoole_process::kill($pid, 9);
                @unlink($filename);
            }
            echo('Stop of Framework Success!' . PHP_EOL);
        }else echo('Framework not started!' . PHP_EOL);
        sleep(1);
        self::start();
    }

    /**
     * 重载(热重启)框架
     */
    Static Public function reload()
    {
        $pid = @file_get_contents(TMP_PATH . 'server.pid');
        if($pid){
            swoole_process::kill($pid, SIGUSR2);
            die("Reload signal has been issued!" . PHP_EOL);
        }
        die("Framework not started!" . PHP_EOL);
    }

    /**
     * 加载文件
     * @param string $filepath 文件路径
     * @param boolean $return 是否获取返回值
     */
    Static Public function loadFiles(string $filepath, $return = false)
    {
        if(!is_file($filepath)){
            self::error($filepath . " does not exist!", E_USER_ERROR);
        }
        if($return === true)
            return include $filepath;
        elseif(is_array($return))
            extract($return);
        include $filepath;
    }

    /**
     * 加载配置文件
     * @param string $name 配置文件名称
     */
    Static Private function loadConf(string $name = 'config')
    {
        $config = self::loadFiles(LIB_PATH . 'ini/' . $name . INI_EXT, true);
        $_config = [];
        $files = scandir(COMMON_PATH . 'config/');
        foreach($files as $file){
            if(strpos($file, INI_EXT) > 0){
                $_config_ = self::loadFiles(COMMON_PATH . 'config/' . $file, true);
                if($file == 'config' . INI_EXT)
                    $_config = array_mer($_config_, $_config);
                else
                    $_config = array_mer($_config, $_config_);
            }
        }
        if(!empty($_config)){
            $config = array_mer($config, $_config);
        }
        return $config;
    }


    /**
     * 错误输出
     * @param string $title 错误标识
     * @param string $content 错误内容
     * @param string $type 错误类型 [E_USER_NOTICE, E_USER_ERROR, E_USER_WARNING]
     */
    Static Public function error(string $title, $content = null, string $type = null)
    {
        if(is_numeric($content)){
            $type = $content;
            $content = null;
        }
        \Root\Console::input($title, $content, $type);
    }

    /**
     * 自动加载所有类(包括应用类)
     */
    Static Public function loadClass(string $dir = '')
    {
        $map = get_declared_classes();
        //加载框架类库
        $dir = rtrim($dir?:LIB_PATH, '/');
        $files = scandir($dir);
        $files = array_diff($files, ['.', '..']);
        $dirs = [];
        foreach($files as $file){
            $path = $dir .'/'. $file;
            if(is_dir($path)){
                $dirs[] = $path;
            }elseif(strpos($file, CLS_EXT) > 0){
                self::loadFiles($path);
                foreach(get_declared_classes() as $classname){
                    if(!isset(self::$map[$classname]) && !in_array($classname, $map))
                        self::$map[$classname] = [
                            'path' => $path,
                            'classname' => trim(strrchr($classname, '\\'), '\\'),
                            'vars' => array_keys(get_class_vars($classname)),
                            'methods' => get_class_methods($classname)
                        ];
                }
            }
        }
        foreach($dirs as $dir){
            self::loadClass($dir);
        }
    }

    /**
     * 加载工具函数及类库
     */
    Static Public function loadUtil(string $dir = '')
    {
        $dir = rtrim($dir?:LIB_PATH, '/');
        $files = scandir($dir);
        $files = array_diff($files, ['.', '..']);
        foreach($files as $file){
            $path = $dir . '/' . $file;
            if(is_dir($path)){
                self::loadUtil($path);
            }elseif(strpos($file, FUN_EXT) > 0){
                //加载函数库
                self::loadFiles($path);
            }
        }
    }

    /**
     * 创建应用场景
     */
    Static Private function create($xml, $path)
    {
        unset($xml->comment);
        $_path = $path;
        if(!empty($attr = $xml->attributes())){
            $_path .= $attr['name'] . '/';
            mkdir($_path, 0777);
            chmod($_path, 0777);
        }
        foreach($xml as $type => $obj){
            if($type == '@attributes')continue;
            if($type == 'dir'){
                self::create($obj, $_path);
            }else{
                $filename = $_path . $obj->attributes()['name'] . '.' . $type . '.php';
                file_put_contents($filename, trim($obj[0]) . PHP_EOL);
                chmod($filename, 0777);
            }
        }
    }
}

