#QMWorker
用来跑脚本的工具类（待完善）

##Requires
PCNTL extensions for PHP

##Basic Usage

```php
<?php
redis = new Redis() ;
$redis->connect("127.0.0.1",6379) ;

$worker = new \QMWorker\QMWorker() ;

/*无穷？*/
$worker->infinite = true ;
/*无穷时，必填*/
$worker->sleep = 10 ;
/*有穷时，必填*/
$worker->frequency = 5 ;

/*开始搬运*/
$worker->onWorkerStart = function() use ($redis){
    /**
     * @var Redis $redis
     */
    $redis->incr("qmore",10) ;
    echo "1\n" ;
} ;

/*结束时释放资源*/
$worker->onWorkerStop = function() use ($redis){
    /**
     * @var Redis $redis
     */
    $redis->close() ;
} ;

$worker->run() ;	
```


