<?php

/**
 * @author Qmore
 */
namespace QMWorker\Events;

/**
 * Interface QMEventInterface
 * @package QMWorker\Events
 */
interface QMEventInterface {

    public function onStartWorker() ;

    public function onProcessWorker() ;

    public function onEndWorker() ;

}