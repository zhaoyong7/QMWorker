<?php
/**
 * Created by PhpStorm.
 * User: Qmore
 * Date: 16-5-4
 * Time: 上午11:52
 */

namespace QMWorker\Events;


use QMWorker\QMWorker;

abstract class QMWorkerAbstract extends QMWorker{

    abstract public function onWorkerStart() ;

    abstract public function onWorkerRun() ;

    abstract public function onWorkerEnd() ;

} 