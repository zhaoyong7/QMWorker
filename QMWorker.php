<?php
/**
 * Created by PhpStorm.
 * User: Qmore
 * Date: 16-4-22
 * Time: 上午9:12
 */

namespace QMWorker;


class QMWorker {

    public $pidFile       = '' ;

    public $logFile       = '' ;

    public $startFile     = '' ;

    public $daemonize     = false ;

    public $infinite      = true ;

    public $sleep         = 1 ;

    public $frequency     = 1 ;

    public $onWorkerStart = null ;

    public $onWorkerStop  = null ;

    public static $_masterPid = 0 ;

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
     *
     */
    public function run(){
        $this->init() ;

        $this->parseCommand() ;

        $this->daemonize() ;

        $this->savePid() ;

        $this->installSignal() ;

        $this->_run() ;
    }

    public function savePid(){
        self::$_masterPid = posix_getpid();
        if(false === @file_put_contents($this->pidFile, self::$_masterPid))
        {
            throw new \Exception('can not save pid to ' . $this->pidFile);
        }
    }
    /**
     * @author chenzsh 16-4-22
     */
    private function _run(){
        if($this->status !== self::STATUS_STARTING)
        {
            exit("status not starting\n") ;
        }
        /*无穷*/
        if($this->infinite && is_numeric($this->sleep))
        {
            while($this->infinite)
            {
                if($this->onWorkerStart)
                {
                    call_user_func($this->onWorkerStart, $this);
                }
                $left = sleep($this->sleep) ;
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
                    call_user_func($this->onWorkerStart, $this);
                }
                $this->frequency -- ;
                pcntl_signal_dispatch() ;
            }
        }
    }

    /**
     * 初始化一些信息
     */
    private function init(){
        if(empty($this->pidFile)){
            $backTrace = debug_backtrace() ;
            $this->startFile = $backTrace[count($backTrace)-1]['file'] ;
            $this->pidFile = __DIR__ . "/../".str_replace('/', '_', $this->startFile).".pid";
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
        if(!isset($argv[1]))
        {
            exit("Usage: php yourfile.php {start|stop|status|kill}\n");
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
        self::log("QMWorker[$this->startFile] $command $mode") ;

        // 检查主进程是否在运行
        $master_pid = @file_get_contents($this->pidFile);
        $master_is_alive = $master_pid && @posix_kill($master_pid, 0);
        if($master_is_alive)
        {
            if($command === 'start')
            {
                self::log("QMWorker[$this->startFile] already running");
                exit;
            }
        }
        elseif($command !== 'start')
        {
            self::log("QMWorker[$this->startFile] not run");
            exit;
        }

        // 根据命令做相应处理
        switch($command)
        {
            case 'kill':
                exec("ps aux | grep $this->startFile | grep -v grep | awk '{print $2}' |xargs kill -SIGINT");
                exec("ps aux | grep $this->startFile | grep -v grep | awk '{print $2}' |xargs kill -SIGKILL");
                break;
            // 启动 QMWorker
            case 'start':
                if($command2 === '-d')
                {
                    $this->daemonize = true;
                }
                break;
            case 'stop':
                self::log("QMWorker[$this->startFile] is stoping ...");
                $master_pid && posix_kill($master_pid, SIGINT);
                self::log("QMWorker[$this->startFile] stop success");
                exit(0);
            // 未知命令
            default :
                exit("Usage: php yourfile.php {start|stop|restart|reload|status|kill}\n");
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
                @unlink($this->pidFile);
                self::log("QMWorker[".basename($this->startFile)."] has been stopped");
                if($this->onWorkerStop)
                {
                    call_user_func($this->onWorkerStop, $this);
                }
                exit(0);
                break;
        }
    }

}
