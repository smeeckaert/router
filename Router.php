<?php

namespace Router;

use Tools\Arr;

class Router
{

    private $_params = array();
    private $_routes = array();
    /**
     * Store route segments in an associative array [nb_of_segment] => [segments]
     *
     * @var array
     */
    private $_cacheRoute = array();
    /**
     * Cache of matching url parameters
     *
     * @var array
     */
    private $_cacheParams = array();

    /**
     * Store parameters fields
     *
     * @var array
     */
    private $_cacheProperty = array();
    // Invalidate cacheRoute
    private $_cacheRouteStatus = false;
    // Invalider cacheProperty
    private $_cachePropertyStatus = false;
    const ROUTE_SEPARATOR = '/';
    const PARAM_SEMAPHOR = ':';
    // Store matched action
    private $_action = null;

    public function addParam($name, $attributes)
    {
        $this->_params[$name] = $attributes;
        $this->_cacheStatus   = false;
    }

    /**
     * Add a route to a controller and an action
     *
     * @param $route
     * @param $controller
     * @param $action
     */
    public function addRoute($route, $controller, $action)
    {
        $this->_routes[$route] = array('controller' => $controller, 'action' => $action);
        $this->_cacheStatus    = false;
    }

    /**
     * Get the best possible URL with the given params
     *
     * @param array $params
     *
     * @return bool|string
     */
    public function getUrl($params = array())
    {
        $bestRouteParameters = 0;
        $bestRoute           = null;
        if (isset($params['route'])) {
            return $this->buildRoute($params, $this->explodeRoute($params['route']));
        }
        $this->initCacheRoute();
        // Find route with the more matching parameters
        foreach ($this->_cacheRoute as $count => $cachedRoutes) {
            foreach ($cachedRoutes as $key => $route) {
                $match      = true;
                $matchCount = 0;
                // Try to match all parameters
                foreach ($route['route'] as $param) {
                    $extract = $this->extractParameter($param);
                    if (!empty($extract) && empty($params[$extract])) {
                        $match = false;
                        break;
                    }
                    if (!empty($extract)) {
                        $matchCount++;
                    }
                }
                // if we match the more parameter, keep this route
                if ($match && $matchCount > $bestRouteParameters) {
                    $bestRouteParameters = $matchCount;
                    $bestRoute           = $route;
                }
            }
        }
        if (!empty($bestRoute)) {
            return $this->buildRoute($params, $bestRoute['route']);
        }
        return false;
    }


    /**
     * Dispatch routeur parameters to a controller and execute the matching action
     *
     * @param \Controller\Controller $controller
     */
    public function dispatch($controller)
    {
        $controller->setParams($this->_cacheParams);
        return $controller->action($this->_action);
    }

    /**
     * Return the matching controller for the URL
     *
     * @param $url
     *
     * @return mixed
     * @throws Exception\NotFound
     */
    public function getController($url)
    {
        $route = $this->explodeRoute($url);
        $this->initCacheRoute();
        $cArgs = count($route);
        if (!isset($this->_cacheRoute[$cArgs])) {
            throw new Exception\NotFound;
        }
        $matchingRoute = $this->findMatchingRoutes($route, $this->_cacheRoute[$cArgs]);
        if (empty($matchingRoute)) {
            throw new Exception\NotFound;
        }
        $params = $matchingRoute['params'];
        var_dump($params);
        var_dump($matchingRoute);
        $this->_action = $params['action'];
        var_dump($this->_cacheParams);
        return new $params['controller'];
    }

    /**
     * Explode a route into segments
     *
     * @param $route
     *
     * @return array
     */
    private function explodeRoute($route)
    {
        return array_values(array_filter(explode(self::ROUTE_SEPARATOR, $route)));
    }

    /**
     * Build a an url according to a route and parameters
     *
     * @param $params
     * @param $route
     *
     * @return string
     */
    protected function buildRoute($params, $route)
    {
        $this->initCacheProperty();
        // Replace route parameters with values
        $route = array_map(function ($i) use ($params) {
            $extract = $this->extractParameter($i);
            if (!empty($extract)) {
                if (isset($this->_cacheProperty[$extract])) {
                    $prop = $this->_cacheProperty[$extract];
                    return $params[$extract]->$prop;
                } else {
                    return $params[$extract];
                }
            }
            return $i;
        }, $route);
        return '/' . implode('/', $route);
    }

    /**
     * Returns the name of a parameter or null if it's not a parameter
     *
     * @param $str
     *
     * @return null|string
     */
    private function extractParameter($str)
    {
        return ($str[0] == self::PARAM_SEMAPHOR ? substr($str, 1) : null);
    }


    /**
     * Put in cache database property of models fields
     * Will try VirtualName and VirtualPath enhancers if no fields are given
     *
     * Then the database field will be stored in $_cacheProperty[name]
     */
    protected function initCacheProperty()
    {
        if ($this->_cacheProperty !== null && $this->_cachePropertyStatus === true) {
            return;
        }
        $this->_cachePropertyStatus = true;
        foreach ($this->_params as $key => $params) {
            $model      = Arr::get($params, 'model');
            $field_name = null;

            if (isset($params['field'])) {
                $field_name = $params['field'];
            } elseif ($model) {
                $traits = class_uses($model);
                if (!empty($traits['Orm\Traits\Url'])) {
                    $field_name = 'url';
                }
            }
            if (!empty($field_name)) {
                $this->_cacheProperty[$key] = $field_name;
            }
        }
        if (isset($this->_cacheProperty['route'])) {
            throw new \Exception("You can't use 'route' as a parameter name");
        }
    }

    /**
     * Init the cache route
     * Will store routes by size in $_cacheRoute
     */
    private function initCacheRoute()
    {
        if ($this->_cacheRoute !== null && $this->_cacheRouteStatus === true) {
            return;
        }
        $this->_cacheRouteStatus = true;
        $this->_cacheRoute       = array();
        foreach ($this->_routes as $route => $params) {
            $route = $this->explodeRoute($route);
            $c     = count($route);
            if (!isset($this->_cacheRoute[$c])) {
                $this->_cacheRoute[$c] = array();
            }
            $this->_cacheRoute[$c][] = array('route' => $route, 'params' => $params);
        }
    }

    /**
     * Find the first matching route
     *
     * @param $route
     * @param $cachedRoutes
     *
     * @return null
     */
    private function findMatchingRoutes($route, $cachedRoutes)
    {
        $matchingRoute = null;
        foreach ($cachedRoutes as $testingRoute) {
            if (($params = $this->testRoute($testingRoute['route'], $route)) !== false) { // Test matching with current route
                $matchingRoute      = $testingRoute;
                $this->_cacheParams = $params;
                break;
            }
        }
        return $matchingRoute;
    }

    /**
     * Test if all arguments of the route are filled with good values
     *
     * @param $route
     * @param $parameters
     *
     * @return array|bool
     */
    private function testRoute($route, $parameters)
    {
        $cacheParams = array();
        foreach ($route as $key => $routeElement) {
            $extractParam      = $this->extractParameter($routeElement);
            $matchingParameter = $parameters[$key];
            if ($extractParam) {
                $matchingElement = $this->testParam($extractParam, $matchingParameter);
                if (empty($matchingElement)) { // Matching parameters
                    return false;
                }
                $cacheParams[$extractParam] = $matchingElement;
            } elseif ($routeElement != $parameters[$key]) { // Matching string parts of the route
                return false;
            }
        }
        return $cacheParams;
    }

    /**
     * Test if a param match his configuration
     *
     * @param $param
     * @param $value
     *
     * @return bool|null
     * @throws \Exception
     */
    private function testParam($param, $value)
    {
        $paramsInfos = $this->_params[$param];
        if (empty($paramsInfos)) { // No params, we always match this parameter
            return $value;
        }
        if (isset($paramsInfos['match'])) { // Callable property to match the variable
            if (!is_callable($paramsInfos['match'])) {
                throw new \Exception("Match parameter must be callable");
                return null;
            } else {
                if ($paramsInfos['match']($value)) {
                    return $value;
                }
                return false;
            }
        }
        if (isset($paramsInfos['model'])) {
            $model = $this->findModel($param, $paramsInfos, $value);
            if (!empty($model)) {
                return $model;
            }
        }
        return false;
    }

    /**
     * Try to find a model matching the route parameters
     *
     * @param $paramKey
     * @param $params
     * @param $value
     *
     * @return null|Model
     * @throws \Exception
     */
    private function findModel($paramKey, $params, $value)
    {
        $model      = $params['model'];
        $field_name = null;
        $find       = "$model::find";
        if (!is_callable($find)) {
            throw new \Exception("Model must have a `find` method");
        }

        $this->initCacheProperty();
        $field_name = Arr::get($this->_cacheProperty, $paramKey);
        if (empty($field_name)) {
            return null;
        }
        $properties = array('and_where' => array($field_name => $value), 'limit' => 1);
        return call_user_func($find, $properties);
        if (get_class($query) != 'Nos\Orm\Query') {
            throw new \Exception("Query method must return a Nos\Orm\Query");
        }
        return $query->where($field_name, $value)->get_one();
    }

}