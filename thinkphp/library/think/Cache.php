<?php
/**
 * 缓存操作类模板方法设计模式
 */

namespace think;

use think\cache\Driver;

class Cache
{
    protected static $instance = []; // 缓存连接实例
    public static $readTimes   = 0;
    public static $writeTimes  = 0;

    /**
     * 操作句柄
     * @var object
     * @access protected
     */
    protected static $handler;

    /**
     * 连接缓存
     * @access public
     * @param array         $options  配置数组
     * @param bool|string   $name 缓存连接标识 true 强制重新连接
     * @return Driver
     */
    public static function connect(array $options = [], $name = false)
    {
        $type = !empty($options['type']) ? $options['type'] : 'File'; // 获取缓存的类型，默认使用文件缓存
        if (false === $name) {
            $name = md5(serialize($options)); // 默认连接名称
        }

        if (true === $name || !isset(self::$instance[$name])) {
            $class = false !== strpos($type, '\\') ? $type : '\\think\\cache\\driver\\' . ucwords($type);

            // 记录初始化信息
            App::$debug && Log::record('[ CACHE ] INIT ' . $type, 'info');
            if (true === $name) {
                return new $class($options); // 如果name为true，则直接返回新的连接，但不存储
            } else {
                self::$instance[$name] = new $class($options); // 存储一个新的连接实例
            }
        }
        return self::$instance[$name];
    }

    /**
     * 自动初始化缓存
     * @access public
     * @param array         $options  配置数组
     * @return Driver
     */
    public static function init(array $options = [])
    {
        if (is_null(self::$handler)) {
            // 自动初始化缓存
            if (!empty($options)) {
                $connect = self::connect($options);
            } elseif ('complex' == Config::get('cache.type')) {
                $connect = self::connect(Config::get('cache.default'));
            } else {
                $connect = self::connect(Config::get('cache'));
            }
            self::$handler = $connect;
        }
        return self::$handler;
    }

    /**
     * 切换缓存类型 需要配置 cache.type 为 complex
     * @access public
     * @param string $name 缓存标识
     * @return Driver
     */
    public static function store($name = '')
    {
        if ('' !== $name && 'complex' == Config::get('cache.type')) {
            return self::connect(Config::get('cache.' . $name), strtolower($name));
        }
        return self::init(); // 否则使用原先的缓存
    }

    /**
     * 判断缓存是否存在
     * @access public
     * @param string $name 缓存变量名
     * @return bool
     */
    public static function has($name)
    {
        self::$readTimes++;
        return self::init()->has($name);
    }

    /**
     * 读取缓存
     * @access public
     * @param string $name 缓存标识
     * @param mixed  $default 默认值
     * @return mixed
     */
    public static function get($name, $default = false)
    {
        self::$readTimes++;
        return self::init()->get($name, $default);
    }

    /**
     * 写入缓存
     * @access public
     * @param string        $name 缓存标识
     * @param mixed         $value  存储数据
     * @param int|null      $expire  有效时间 0为永久
     * @return boolean
     */
    public static function set($name, $value, $expire = null)
    {
        self::$writeTimes++;
        return self::init()->set($name, $value, $expire);
    }

    /**
     * 自增缓存（针对数值缓存）
     * @access public
     * @param string    $name 缓存变量名
     * @param int       $step 步长
     * @return false|int
     */
    public static function inc($name, $step = 1)
    {
        self::$writeTimes++;
        return self::init()->inc($name, $step);
    }

    /**
     * 自减缓存（针对数值缓存）
     * @access public
     * @param string    $name 缓存变量名
     * @param int       $step 步长
     * @return false|int
     */
    public static function dec($name, $step = 1)
    {
        self::$writeTimes++;
        return self::init()->dec($name, $step);
    }

    /**
     * 删除缓存
     * @access public
     * @param string    $name 缓存标识
     * @return boolean
     */
    public static function rm($name)
    {
        self::$writeTimes++;
        return self::init()->rm($name);
    }

    /**
     * 清除缓存
     * @access public
     * @param string $tag 标签名
     * @return boolean
     */
    public static function clear($tag = null)
    {
        self::$writeTimes++;
        return self::init()->clear($tag);
    }

    /**
     * 读取缓存并删除
     * @access public
     * @param string $name 缓存变量名
     * @return mixed
     */
    public static function pull($name)
    {
        self::$readTimes++;
        self::$writeTimes++;
        return self::init()->pull($name);
    }

    /**
     * 如果不存在则写入缓存
     * @access public
     * @param string    $name 缓存变量名
     * @param mixed     $value  存储数据
     * @param int       $expire  有效时间 0为永久
     * @return mixed
     */
    public static function remember($name, $value, $expire = null)
    {
        self::$readTimes++;
        return self::init()->remember($name, $value, $expire);
    }

    /**
     * 缓存标签
     * @access public
     * @param string        $name 标签名
     * @param string|array  $keys 缓存标识
     * @param bool          $overlay 是否覆盖
     * @return Driver
     */
    public static function tag($name, $keys = null, $overlay = false)
    {
        return self::init()->tag($name, $keys, $overlay);
    }

}
