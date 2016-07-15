<?php
/**
 * Created by PhpStorm.
 * User: Qmore
 * Date: 16-4-22
 * Time: 上午9:55
 */

namespace QMWorker\Events;


class QMEvent {

    public $sender = null ;

    public $params = null ;

    private static $_events = array() ;

    public function __construct($sender = null,$params = null){
        $this->sender=$sender;
        $this->params=$params;
    }

    public function __set($name,$value)
    {
        $setter='set'.$name;
        if(method_exists($this,$setter))
            return $this->$setter($value);
        elseif(strncasecmp($name,'on',2)===0 && method_exists($this,$name))
        {
            $name=strtolower($name);
            if(!isset(self::$_events[$name]))
                return self::$_events[$name][] = $value ;
        }
    }

    public function raiseEvent($name,$event)
    {
        $name=strtolower($name);
        if(isset(self::$_events[$name]))
        {
            foreach(self::$_events[$name] as $handler)
            {
                if(is_string($handler))
                    call_user_func($handler,$event);
                elseif(is_callable($handler,true))
                {
                    if(is_array($handler))
                    {
                        // an array: 0 - object, 1 - method name
                        list($object,$method)=$handler;
                        if(is_string($object))	// static method call
                            call_user_func($handler,$event);
                        elseif(method_exists($object,$method))
                            $object->$method($event);
                        else
                            return ;
                    }
                    else // PHP 5.3: anonymous function
                        call_user_func($handler,$event);
                }
                else
                    return ;

            }
        }
        else
        {
            return ;
        }
    }
} 