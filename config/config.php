<?php
/**
 * Created by PhpStorm.
 * User: Qmore
 * Date: 16-4-26
 * Time: 上午9:52
 */


$config = [
   'redisIncr' => [
        'class'     => 'applications\RedisIncr' ,
        'infinite'  =>  true ,
        'interval'  =>  1 ,
        'frequency' =>  0 ,
   ],
    'redisIncr2' => [
        'class'     => 'applications\RedisIncr2' ,
        'infinite'  =>  true ,
        'interval'  =>  1 ,
        'frequency' =>  0 ,
    ],
    'redisIncr3' => [
        'class'     => 'applications\RedisIncr3' ,
        'infinite'  =>  true ,
        'interval'  =>  10 ,
        'frequency' =>  0 ,
    ]
] ;
return $config ;