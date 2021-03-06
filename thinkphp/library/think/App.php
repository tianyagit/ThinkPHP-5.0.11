<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2017 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think;

use think\exception\ClassNotFoundException;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\RouteNotFoundException;

/**
 * App 应用管理
 * @author  liu21st <liu21st@gmail.com>
 */
class App
{
    /**
     * @var bool 是否初始化过
     */
    protected static $init = false;

    /**
     * @var string 当前模块路径
     */
    public static $modulePath;

    /**
     * @var bool 应用调试模式
     */
    public static $debug = true;

    /**
     * @var string 应用类库命名空间
     */
    public static $namespace = 'app';

    /**
     * @var bool 应用类库后缀
     */
    public static $suffix = false;

    /**
     * @var bool 应用路由检测
     */
    protected static $routeCheck;

    /**
     * @var bool 严格路由检测
     */
    protected static $routeMust;

    protected static $dispatch;
    protected static $file = [];

    /**
     * 执行应用程序
     * @access public
     * @param Request $request Request对象
     * @return Response
     * @throws Exception
     */
    public static function run(Request $request = null)
    {
         // 实例化请求对象
        is_null($request) && $request = Request::instance();

        try {
            $config = self::initCommon();
            if (defined('BIND_MODULE')) {
                /**
                 * 由于默认是采用多模块的支持，所以多个模块的情况下必须在URL地址中标识当前模块，如果只有一个模块的话，可以进行模块绑定，方法是应用的入口文件中添加如下代码：
                 *
                 * // 绑定当前访问到index模块
                 * define('BIND_MODULE','index');
                 */
                // 模块/控制器绑定
                BIND_MODULE && Route::bind(BIND_MODULE);
            } elseif ($config['auto_bind_module']) {
                /**
                 *  自动绑定会将入口文件名绑定到对应模块。比如index.php入口文件则绑定到index模块。
                 *  admin.php入口文件则绑定到admin模块。同时app目录下必须存在对应模块目录。
                 * 不需要写define('BIND_MODULE','admin');
                 *  如果需要自动绑定到admin模块。则在public目录下建立一个admin.php文件。
                 * 在app目录下建立一个admin目录作为模块目录，然后访问admin.php则会自动绑定。
                 */
                // 入口自动绑定
                $name = pathinfo($request->baseFile(), PATHINFO_FILENAME);
                if ($name && 'index' != $name && is_dir(APP_PATH . $name)) {
                    Route::bind($name);
                }
            }

            $request->filter($config['default_filter']); // 默认全局过滤方法

            // 默认语言
            Lang::range($config['default_lang']);
            if ($config['lang_switch_on']) {
                // 开启多语言机制 检测当前语言
                Lang::detect();
            }
            $request->langset(Lang::range()); // 设置语言

            // 加载系统语言包
            Lang::load([
                THINK_PATH . 'lang' . DS . $request->langset() . EXT,
                APP_PATH . 'lang' . DS . $request->langset() . EXT,
            ]);

            // 获取应用调度信息
            $dispatch = self::$dispatch;
            if (empty($dispatch)) {
                // 进行URL路由检测
                $dispatch = self::routeCheck($request, $config);
            }
            // 记录当前调度信息
            $request->dispatch($dispatch);

            // 记录路由和请求信息
            if (self::$debug) {
                Log::record('[ ROUTE ] ' . var_export($dispatch, true), 'info');
                Log::record('[ HEADER ] ' . var_export($request->header(), true), 'info');
                Log::record('[ PARAM ] ' . var_export($request->param(), true), 'info');
            }

            // 监听app_begin
            Hook::listen('app_begin', $dispatch);
            // 请求缓存检查，第二次访问相同的路由地址的时候，会自动获取请求缓存的数据响应输出，并发送304状态码。
            // 这里通过抛出异常来跳过下面一条语句的执行
            $request->cache($config['request_cache'], $config['request_cache_expire'], $config['request_cache_except']);

            $data = self::exec($dispatch, $config);
        } catch (HttpResponseException $exception) {
            $data = $exception->getResponse(); // 异常发送到客户端
        }

        // 清空类的实例化，把加载器初始化类的实例置为空，节约内存
        Loader::clearInstance();

        // 输出数据到客户端
        if ($data instanceof Response) {
            $response = $data;
        } elseif (!is_null($data)) {
            // 默认自动识别响应输出类型
            $isAjax   = $request->isAjax();
            $type     = $isAjax ? Config::get('default_ajax_return') : Config::get('default_return_type');
            $response = Response::create($data, $type);
        } else {
            $response = Response::create();
        }

        // 监听app_end，应用结束响应发送前的钩子
        Hook::listen('app_end', $response);

        return $response;
    }

    /**
     * 设置当前请求的调度信息
     * @access public
     * @param array|string  $dispatch 调度信息
     * @param string        $type 调度类型
     * @return void
     */
    public static function dispatch($dispatch, $type = 'module')
    {
        self::$dispatch = ['type' => $type, $type => $dispatch];
    }

    /**
     * 执行函数或者闭包方法 支持参数调用
     * @access public
     * @param string|array|\Closure $function 函数或者闭包
     * @param array                 $vars     变量
     * @return mixed
     */
    public static function invokeFunction($function, $vars = [])
    {
        $reflect = new \ReflectionFunction($function);
        $args    = self::bindParams($reflect, $vars);
        // 记录执行信息
        self::$debug && Log::record('[ RUN ] ' . $reflect->__toString(), 'info');
        return $reflect->invokeArgs($args);
    }

    /**
     * 调用反射执行类的方法 支持参数绑定
     * @access public
     * @param string|array $method 方法
     * @param array        $vars   变量
     * @return mixed
     */
    public static function invokeMethod($method, $vars = [])
    {
        if (is_array($method)) {
            $class   = is_object($method[0]) ? $method[0] : self::invokeClass($method[0]);
            $reflect = new \ReflectionMethod($class, $method[1]);
        } else {
            // 静态方法
            $reflect = new \ReflectionMethod($method);
        }
        $args = self::bindParams($reflect, $vars); // 绑定参数到方法

        self::$debug && Log::record('[ RUN ] ' . $reflect->class . '->' . $reflect->name . '[ ' . $reflect->getFileName() . ' ]', 'info');
        return $reflect->invokeArgs(isset($class) ? $class : null, $args);
    }

    /**
     * 调用反射执行类的实例化 支持依赖注入
     * @access public
     * @param string    $class 类名
     * @param array     $vars  变量
     * @return mixed
     */
    public static function invokeClass($class, $vars = [])
    {
        $reflect     = new \ReflectionClass($class);
        $constructor = $reflect->getConstructor();
        if ($constructor) {
            $args = self::bindParams($constructor, $vars);
        } else {
            $args = [];
        }
        return $reflect->newInstanceArgs($args);
    }

    /**
     * 绑定参数
     * @access private
     * @param \ReflectionMethod|\ReflectionFunction $reflect 反射类
     * @param array                                 $vars    变量
     * @return array
     */
    private static function bindParams($reflect, $vars = [])
    {
        if (empty($vars)) {
            // 自动获取请求变量
            if (Config::get('url_param_type')) { // url中参数是按名值对解析还是按顺序解析
                $vars = Request::instance()->route(); // 按顺序解析
            } else {
                $vars = Request::instance()->param(); // 按名值对解析
            }
        }
        $args = [];
        if ($reflect->getNumberOfParameters() > 0) {
            // 判断数组类型 数字数组时按顺序绑定参数
            reset($vars);
            $type   = key($vars) === 0 ? 1 : 0; // 是顺序解析还是名值对解析，0为名值解析，1为顺序解析
            $params = $reflect->getParameters();
            foreach ($params as $param) {
                $args[] = self::getParamValue($param, $vars, $type);
            }
        }
        return $args;
    }

    /**
     * 获取参数值
     * @access private
     * @param \ReflectionParameter  $param
     * @param array                 $vars    变量
     * @param string                $type
     * @return array
     */
    private static function getParamValue($param, &$vars, $type)
    {
        $name  = $param->getName(); // 参数名称
        $class = $param->getClass(); // 参数对象类型
        if ($class) {
            $className = $class->getName();
            $bind      = Request::instance()->$name; // 1、从Request对象属性中获取参数对象
            if ($bind instanceof $className) {
                $result = $bind;
            } else {
                // 2、如果依赖注入的类有定义一个可调用的静态invoke方法，则会自动调用invoke方法完成依赖注入的自动实例化。
                if (method_exists($className, 'invoke')) {
                    $method = new \ReflectionMethod($className, 'invoke');
                    if ($method->isPublic() && $method->isStatic()) {
                        return $className::invoke(Request::instance());
                    }
                }
                // 3、如果类中存在instance方法，则直接通过该方法返回该类实例对象，否则重新创建一个
                // 这里实现对象参数的自动注入
                $result = method_exists($className, 'instance') ? $className::instance() : new $className;
            }
        } elseif (1 == $type && !empty($vars)) { // 顺序参数
            $result = array_shift($vars);
        } elseif (0 == $type && isset($vars[$name])) { //名值对参数
            $result = $vars[$name];
        } elseif ($param->isDefaultValueAvailable()) {
            $result = $param->getDefaultValue();
        } else {
            throw new \InvalidArgumentException('method param miss:' . $name);
        }
        return $result;
    }

    protected static function exec($dispatch, $config)
    {
        switch ($dispatch['type']) {
            case 'redirect':
                // 执行重定向跳转
                $data = Response::create($dispatch['url'], 'redirect')->code($dispatch['status']);
                break;
            case 'module':
                // 模块/控制器/操作
                $data = self::module($dispatch['module'], $config, isset($dispatch['convert']) ? $dispatch['convert'] : null);
                break;
            case 'controller':
                // 执行控制器操作
                $vars = array_merge(Request::instance()->param(), $dispatch['var']);
                $data = Loader::action($dispatch['controller'], $vars, $config['url_controller_layer'], $config['controller_suffix']);
                break;
            case 'method':
                // 执行回调方法
                $vars = array_merge(Request::instance()->param(), $dispatch['var']);
                $data = self::invokeMethod($dispatch['method'], $vars);
                break;
            case 'function':
                // 执行闭包
                $data = self::invokeFunction($dispatch['function']);
                break;
            case 'response':
                $data = $dispatch['response'];
                break;
            default:
                throw new \InvalidArgumentException('dispatch type not support');
        }
        return $data;
    }

    /**
     * 执行模块
     * @access public
     * @param array $result 模块/控制器/操作
     * @param array $config 配置参数
     * @param bool  $convert 是否自动转换控制器和操作名
     * @return mixed
     */
    public static function module($result, $config, $convert = null)
    {
        if (is_string($result)) {
            $result = explode('/', $result);
        }
        $request = Request::instance();
        if ($config['app_multi_module']) { // 如果开启多模块
            // 多模块部署
            // 如果没有指定模块名，则使用默认配置模块
            $module    = strip_tags(strtolower($result[0] ?: $config['default_module']));
            $bind      = Route::getBind('module');
            $available = false;
            if ($bind) {
                // 绑定模块
                list($bindModule) = explode('/', $bind);
                if (empty($result[0])) {
                    $module    = $bindModule;
                    $available = true;
                } elseif ($module == $bindModule) {
                    $available = true;
                }
            } elseif (!in_array($module, $config['deny_module_list']) && is_dir(APP_PATH . $module)) {
                $available = true;
            }

            // 模块初始化
            if ($module && $available) {
                // 初始化模块
                $request->module($module);
                $config = self::init($module);
                // 模块请求缓存检查，因为模块可能对请求缓存重新进行配置
                $request->cache($config['request_cache'], $config['request_cache_expire'], $config['request_cache_except']);
            } else {
                throw new HttpException(404, 'module not exists:' . $module);
            }
        } else {
            // 单一模块部署
            $module = '';
            $request->module($module);
        }
        // 当前模块路径
        App::$modulePath = APP_PATH . ($module ? $module . DS : '');

        // 是否自动转换控制器和操作名，如果开启模块名和操作名会直接转换为小写处理。一旦关闭自动转换，URL地址中的控制器名就变成大小写敏感了
        $convert = is_bool($convert) ? $convert : $config['url_convert'];
        // 获取控制器名
        $controller = strip_tags($result[1] ?: $config['default_controller']);
        $controller = $convert ? strtolower($controller) : $controller;

        // 获取操作名
        $actionName = strip_tags($result[2] ?: $config['default_action']);
        $actionName = $convert ? strtolower($actionName) : $actionName;

        // 设置当前请求的控制器、操作
        $request->controller(Loader::parseName($controller, 1))->action($actionName);

        // 监听module_init
        Hook::listen('module_init', $request);

        try {
            // 空控制器的概念是指当系统找不到指定的控制器名称的时候，系统会尝试定位空控制器，空控制器可以配置，默认为Error类
            $instance = Loader::controller($controller, $config['url_controller_layer'], $config['controller_suffix'], $config['empty_controller']);
        } catch (ClassNotFoundException $e) {
            throw new HttpException(404, 'controller not exists:' . $e->getClass());
        }

        // 获取当前操作名
        $action = $actionName . $config['action_suffix'];

        $vars = [];
        if (is_callable([$instance, $action])) {
            // 执行操作方法
            $call = [$instance, $action];
        } elseif (is_callable([$instance, '_empty'])) { // 如果不存在该动作，且空操作存在，则调用空操作
            // 空操作
            $call = [$instance, '_empty'];
            $vars = [$actionName];
        } else {
            // 操作不存在
            throw new HttpException(404, 'method not exists:' . get_class($instance) . '->' . $action . '()');
        }

        Hook::listen('action_begin', $call); // 动作执行前的钩子

        return self::invokeMethod($call, $vars); // 执行动作
    }

    /**
     * 初始化应用
     */
    public static function initCommon()
    {
        if (empty(self::$init)) {
            if (defined('APP_NAMESPACE')) { // 通过使用APP_NAMESPACE常量定义来修改应用的命名空间，默认为app
                self::$namespace = APP_NAMESPACE;
            }
            // 配置应用命名空间与文件的对应关系
            Loader::addNamespace(self::$namespace, APP_PATH);

            // 初始化应用
            $config       = self::init();
            self::$suffix = $config['class_suffix'];

            // 应用调试模式
            self::$debug = Env::get('app_debug', Config::get('app_debug'));
            if (!self::$debug) {
                ini_set('display_errors', 'Off');
            } elseif (!IS_CLI) {
                //重新申请一块比较大的buffer
                if (ob_get_level() > 0) {
                    $output = ob_get_clean();
                }
                ob_start();
                if (!empty($output)) {
                    echo $output;
                }
            }

            if (!empty($config['root_namespace'])) { // ???
                Loader::addNamespace($config['root_namespace']);
            }

            // 加载额外文件
            if (!empty($config['extra_file_list'])) {
                foreach ($config['extra_file_list'] as $file) {
                    $file = strpos($file, '.') ? $file : APP_PATH . $file . EXT;
                    if (is_file($file) && !isset(self::$file[$file])) {
                        include $file;
                        self::$file[$file] = true;
                    }
                }
            }

            // 设置系统时区
            date_default_timezone_set($config['default_timezone']);

            // 监听app_init
            Hook::listen('app_init');

            self::$init = true;
        }
        return Config::get();
    }

    /**
     * 初始化应用或模块
     * @access public
     * @param string $module 模块名
     * @return array
     */
    private static function init($module = '')
    {
        // 定位模块目录
        $module = $module ? $module . DS : '';

        // 加载模块初始化文件，如果没有指定模块，则加载应用初始化文件
        if (is_file(APP_PATH . $module . 'init' . EXT)) {
            include APP_PATH . $module . 'init' . EXT;
        } elseif (is_file(RUNTIME_PATH . $module . 'init' . EXT)) { // 加载模块运行时初始化文件
            include RUNTIME_PATH . $module . 'init' . EXT;
        } else {
            $path = APP_PATH . $module;
            // 加载模块配置
            $config = Config::load(CONF_PATH . $module . 'config' . CONF_EXT);
            // 读取模块数据库配置文件
            $filename = CONF_PATH . $module . 'database' . CONF_EXT;
            Config::load($filename, 'database');
            // 读取模块扩展配置文件
            if (is_dir(CONF_PATH . $module . 'extra')) {
                $dir   = CONF_PATH . $module . 'extra';
                $files = scandir($dir);
                foreach ($files as $file) {
                    if ('.' . pathinfo($file, PATHINFO_EXTENSION) === CONF_EXT) {
                        $filename = $dir . DS . $file;
                        Config::load($filename, pathinfo($file, PATHINFO_FILENAME));
                    }
                }
            }

            // 加载应用状态配置，每个应用都可以在不同的情况下设置自己的状态（或者称之为应用场景），并且加载不同的配置文件。
            // 'app_status'=>'office'，那么就会自动加载该状态对应的配置文件（默认位于application[module]/office.php）
            if ($config['app_status']) {
                $config = Config::load(CONF_PATH . $module . $config['app_status'] . CONF_EXT);
            }

            // 加载模块行为扩展文件
            /**
             * 我们也可以直接在APP_PATH目录下面或者模块的目录下面定义tags.php文件来统一定义行为，定义格式如下：
             * return [
                'app_init'=> [
                    'app\\index\\behavior\\CheckAuth',
                    'app\\index\\behavior\\CheckLang'
                ],
                'app_end'=> [
                    'app\\admin\\behavior\\CronRun'
                ]
            ]
             */
            if (is_file(CONF_PATH . $module . 'tags' . EXT)) {
                Hook::import(include CONF_PATH . $module . 'tags' . EXT);
            }

            // 加载应用公共文件
            if (is_file($path . 'common' . EXT)) {
                include $path . 'common' . EXT;
            }

            // 加载当前模块语言包
            if ($module) {
                Lang::load($path . 'lang' . DS . Request::instance()->langset() . EXT);
            }
        }
        return Config::get();
    }

    /**
     * URL路由检测（根据PATH_INFO)
     * @access public
     * @param  \think\Request $request
     * @param  array          $config
     * @return array
     * @throws \think\Exception
     */
    public static function routeCheck($request, array $config)
    {
        $path   = $request->path();
        $depr   = $config['pathinfo_depr'];
        $result = false;
        // 路由检测，如果开启了url_route_on参数的话，会首先进行URL的路由检测。
        $check = !is_null(self::$routeCheck) ? self::$routeCheck : $config['url_route_on'];
        if ($check) {
            // 开启路由
            if (is_file(RUNTIME_PATH . 'route.php')) {
                // 读取路由缓存
                $rules = include RUNTIME_PATH . 'route.php';
                if (is_array($rules)) {
                    Route::rules($rules);
                }
            } else {
                $files = $config['route_config_file']; // 获取路由的配置文件名称
                foreach ($files as $file) {
                    if (is_file(CONF_PATH . $file . CONF_EXT)) {
                        // 导入路由配置
                        $rules = include CONF_PATH . $file . CONF_EXT;
                        if (is_array($rules)) {
                            Route::import($rules);
                        }
                    }
                }
            }

            // 路由检测（根据路由定义返回不同的URL调度）
            $result = Route::check($request, $path, $depr, $config['url_domain_deploy']);
            $must   = !is_null(self::$routeMust) ? self::$routeMust : $config['url_route_must'];
            if ($must && false === $result) { // 如果开启必须使用路由，如果没有匹配路由，则抛出异常
                // 路由无效
                throw new RouteNotFoundException();
            }
        }
        if (false === $result) { // 如果关闭路由或者路由检测无效则进行默认的模块/控制器/操作的分析识别。
            // 路由无效 解析模块/控制器/操作/参数，$config['controller_auto_search']支持控制器自动搜索
            $result = Route::parseUrl($path, $depr, $config['controller_auto_search']);
        }
        return $result;
    }

    /**
     * 设置应用的路由检测机制
     * @access public
     * @param  bool $route 是否需要检测路由
     * @param  bool $must  是否强制检测路由
     * @return void
     */
    public static function route($route, $must = false)
    {
        self::$routeCheck = $route;
        self::$routeMust  = $must;
    }
}
