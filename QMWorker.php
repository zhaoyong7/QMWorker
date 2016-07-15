<?php
/**
 * Created by PhpStorm.
 * User: Qmore
 * Date: 16-4-22
 * Time: 上午9:12
 */

namespace QMWorker;


use closure\closure;

class QMWorker {

    public $workerId      =  0 ;

    public $name          = 'none' ;

    public $infinite      = false ;

    public $frequency     = 0 ;

    /**
     * seconds
     * @var int
     */
    public $interval      = 1 ;

    public static $pidFile = '' ;

    public $logFile       = '' ;

    public static $startFile     = '' ;

    public $daemonize     = false ;

    public $onWorkerStart = null ;

    public $onWorkerStop  = null ;

    /**
     * 重定向标准输出，即将所有echo、var_dump等终端输出写到对应文件中
     * 注意 此参数只有在以守护进程方式运行时有效
     * @var string
     */
    public static $stdoutFile = '/dev/null';

    public $status = self::STATUS_STARTING ;

    /**
     * 状态 启动中
     * @var int
     */
    const STATUS_STARTING = 1;

    /**
     * 状态 运行中
     * @var int
     */
    const STATUS_RUNNING = 2;

    /**
     * 状态 停止
     * @var int
     */
    const STATUS_SHUTDOWN = 4;

    /**
     * @var int 主进程ID
     */
    protected static $_masterPid = 0 ;
    /**
     * 脚本事件对象
     * @var array
     */
    public static $_workers = [] ;
    /**
     * 每个脚本配置 id => $config
     * @var array
     */
    private static $_definitions = [] ;

    public static $_pidMap = [] ; // [workerId => pid ,workerId => pid] ,实例hash对应进程id

    public function __construct($configs = null){
        $this->workerId = spl_object_hash($this);
        if($configs === null){
            return ;
        }
        /**
         * 保存每个脚本对象实例
         */
        foreach($configs as $id => $config){
            $object = $this->createObject($config) ;
            $object->workerId = spl_object_hash($object);
            self::$_definitions[$object->workerId] = $config ;
            self::$_workers[$object->workerId] = $object;
        }
    }

    /**
     * @param $config
     * @return object
     */
    public function createObject($config){
        $class = $config['class'] ;
        unset($config['class']) ;
        $reflection = new \ReflectionClass($class);
        $object = $reflection->newInstanceArgs();
        foreach ($config as $name => $value) {
            $object->$name = $value;
        }
        return $object ;
    }

    public function runAll(){
        $this->init() ;

        $this->parseCommand() ;

        $this->daemonize() ;

        $this->saveMasterPid();

        $this->forkWorkers() ;

        $this->monitorWorkers() ;
    }

    public function forkWorkers(){
        foreach(self::$_workers as $worker)
        {
            $this->forkOneWorker($worker);
        }
    }

    /**
     * @param QMWorker $worker
     * @throws \Exception
     */
    public function forkOneWorker($worker){
        $pid = pcntl_fork() ;
        /**
         * master process
         */
        if($pid > 0)
        {
            self::$_pidMap[$worker->workerId] = $pid ;
        }else if(0 === $pid){
            /*子进程一些数据初始化和置空*/
            self::$_pidMap  = [] ;
            self::$_workers = [$worker->workerId => $worker] ;
            $worker->run() ;
            exit(250) ;
        }
        else
        {
            throw new \Exception("forkOneWorker fail");
        }
    }

    public function monitorWorkers(){
        $this->status = self::STATUS_RUNNING ;

        /*主进程阻塞*/
        while(1){
            // 如果有信号到来，尝试触发信号处理函数
            pcntl_signal_dispatch();
            $pid = pcntl_wait($status,WUNTRACED) ;
            // 如果有信号到来，尝试触发信号处理函数
            pcntl_signal_dispatch();
            // 子进程退出
            if($pid > 0 )
            {
                foreach(self::$_pidMap as $workerId => $worker_pid){
                    if($worker_pid == $pid){
                        /**
                         * @var $worker QMWorker
                         */
                        $worker = self::$_workers[$workerId];

                        // 检查退出状态
                        if($status !== 0)
                        {
                            self::log("worker[".$worker->name.":$pid] exit with status $status");
                        }
                        // 清除子进程信息
                        unset(self::$_pidMap[$workerId]);
                        break ;
                    }
                }

                if(empty(self::$_pidMap))
                {
                    self::exitAndClearAll() ;
                }

            }

        }
    }

    public function run(){

        if($this->status !== self::STATUS_STARTING)
        {
            exit("status not starting\n") ;
        }

        if(method_exists($this,'onWorkerStart'))
        {
            call_user_func([$this,'onWorkerStart']);
        }
            /*无穷*/
        if($this->infinite && is_numeric($this->interval))
        {
            while($this->infinite)
            {
                if(method_exists($this,'onWorkerRun'))
                {
                    call_user_func([$this,'onWorkerRun']);
                }
                $left = sleep($this->interval) ;
                pcntl_signal_dispatch() ;
                while($left > 0){
                    $left = sleep($left) ;
                    pcntl_signal_dispatch() ;
                }
            }
        }else
        {
            while($this->frequency > 0 ){
                if($this->onWorkerStart)
                {
                    call_user_func([$this,'onWorkerRun']);
                }
                $this->frequency -- ;
                pcntl_signal_dispatch() ;
            }
        }
    }

    public function saveMasterPid(){
        self::$_masterPid = posix_getpid();
        if(false === @file_put_contents(self::$pidFile, self::$_masterPid))
        {
            throw new \Exception('can not save pid to ' . self::$pidFile);
        }
    }

    /**
     * 初始化一些信息
     */
    private function init(){
        if(empty(self::$pidFile)){
            $backTrace = debug_backtrace() ;
            self::$startFile = $backTrace[count($backTrace)-1]['file'] ;
            self::$pidFile = __DIR__ . "/../".str_replace('/', '_', self::$startFile).".pid";
        }

        // 没有设置日志文件，则生成一个默认值
        if(empty($this->logFile))
        {
            $this->logFile = __DIR__ . '/../QMWorker.log';
        }

    }

    /**
     *
     */
    private function parseCommand(){
        global $argv ;
        $start_file = $argv[0];
        if(!isset($argv[1]))
        {
            exit("Usage: php yourfile.php {start|stop|kill}\n");
        }
        // 命令
        $command = trim($argv[1]);
        // 子命令，目前只支持-d
        $command2 = isset($argv[2]) ? $argv[2] : '';

        $mode = '';
        if($command === "start")
        {
            if($command2 === '-d')
            {
                $mode = 'in DAEMON mode' ;
            }
            else
            {
                $mode = 'in DEBUG mode'  ;
            }
        }
        self::log("QMWorker[$start_file] $command $mode") ;

        // 检查主进程是否在运行
        $master_pid = @file_get_contents(self::$pidFile);
        $master_is_alive = $master_pid && @posix_kill($master_pid, 0);
        if($master_is_alive)
        {
            if($command === 'start')
            {
                self::log("QMWorker[$start_file] already running");
                exit;
            }
        }
        elseif($command !== 'start')
        {
            self::log("QMWorker[$start_file] not run");
            exit;
        }

        // 根据命令做相应处理
        switch($command)
        {
            case 'kill':
                exec("ps aux | grep $start_file | grep -v grep | awk '{print $2}' |xargs kill -SIGINT");
                exec("ps aux | grep $start_file | grep -v grep | awk '{print $2}' |xargs kill -SIGKILL");
                break;
            // 启动 QMWorker
            case 'start':
                if($command2 === '-d')
                {
                    $this->daemonize = true;
                }
                break;
            case 'stop':
                self::log("QMWorker[$start_file] is stoping ...");
                $master_pid && posix_kill($master_pid, SIGINT);
                self::log("QMWorker[$start_file] stop success");
                exit(0);
            // 未知命令
            default :
                exit("Usage: php yourfile.php {start|stop||kill}\n");
        }
    }

    /**
     * 尝试以守护进程的方式运行
     * @throws Exception
     */
    private function daemonize()
    {
        if(!$this->daemonize)
        {
            return;
        }
        umask(0);
        $pid = pcntl_fork();
        if(-1 === $pid)
        {
            throw new \Exception('fork fail');
        }
        elseif($pid > 0)
        {
            exit(0);
        }
        if(-1 === posix_setsid())
        {
            throw new \Exception("setsid fail");
        }
        // fork again avoid SVR4 system regain the control of terminal
        $pid = pcntl_fork();
        if(-1 === $pid)
        {
            throw new \Exception("fork fail");
        }
        elseif(0 !== $pid)
        {
            exit(0);
        }

        global $STDOUT, $STDERR;
        $handle = fopen(self::$stdoutFile,"a");
        if($handle)
        {
            unset($handle);
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen(self::$stdoutFile,"a");
            $STDERR = fopen(self::$stdoutFile,"a");
        }
        else
        {
            throw new \Exception('can not open stdoutFile ' . self::$stdoutFile);
        }
    }

    /**
     * 安装信号处理函数
     * @return void
     */
    protected function installSignal()
    {
        // stop
        pcntl_signal(SIGINT,  array($this, 'signalHandler'), false);
        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    /**
     * 信号处理函数
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        switch($signal)
        {
            // stop
            case SIGINT:
                @unlink(self::$pidFile);
                self::log("QMWorker[".basename(self::$startFile)."] has been stopped");
                if($this->onWorkerStop)
                {
                    call_user_func($this->onWorkerStop, $this);
                }
                exit(0);
                break;
        }
    }

    /**
     * 退出当前进程
     * @return void
     */
    protected static function exitAndClearAll()
    {
        @unlink(self::$pidFile);
        self::log("Workerman[".basename(self::$startFile)."] has been stopped");
        exit(0);
    }

    /**
     * 记录日志
     * @param string $msg
     * @return void
     */
    public function log($msg)
    {
        $msg = $msg."\n";
        if(!$this->daemonize)
        {
            echo $msg;
        }
        file_put_contents($this->logFile, date('Y-m-d H:i:s') . " " . $msg, FILE_APPEND | LOCK_EX);
    }

}
