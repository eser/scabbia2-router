<?php
/**
 * Scabbia2 Router Component
 * http://www.scabbiafw.com/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link        https://github.com/scabbiafw/scabbia2-router for the canonical source repository
 * @copyright   2010-2015 Scabbia Framework Organization. (http://www.scabbiafw.com/)
 * @license     http://www.apache.org/licenses/LICENSE-2.0 - Apache License, Version 2.0
 */

namespace Scabbia\Router;

/**
 * Router
 *
 * @package     Scabbia\Router
 * @author      Eser Ozvataf <eser@ozvataf.com>
 * @since       2.0.0
 *
 * Routing related code based on the nikic's FastRoute solution:
 * http://nikic.github.io/2014/02/18/Fast-request-routing-using-regular-expressions.html
 */
class Router
{
    /** @type int FOUND              route found */
    const FOUND = 0;
    /** @type int NOT_FOUND          route not found */
    const NOT_FOUND = 1;
    /** @type int METHOD_NOT_ALLOWED route method is not allowed */
    const METHOD_NOT_ALLOWED = 2;


    /** @type array route definitions */
    public $routeDefinitions;
    /** @type bool  translate HEAD method to GET */
    public $translateHeadMethod = true;


    /**
     * Initializes a router
     *
     * @param array  $uRouteDefinitions   route data
     *
     * @return Router
     */
    public function __construct($uRouteDefinitions)
    {
        $this->routeDefinitions = $uRouteDefinitions;
    }

    /**
     * The dispatch method
     *
     * @param string $uMethod   http method
     * @param string $uPathInfo path
     *
     * @return mixed
     */
    public function dispatch($uMethod, $uPathInfo)
    {
        if ($uMethod === "HEAD" && $this->translateHeadMethod) {
            $uMethod = "GET";
        }

        if (isset($this->routeDefinitions["static"][$uPathInfo])) {
            $tRoute = $this->routeDefinitions["static"][$uPathInfo];

            if (isset($tRoute[$uMethod])) {
                return [
                    "status"     => self::FOUND,
                    "callback"   => $tRoute[$uMethod],
                    "parameters" => []
                ];
            } else {
                return [
                    "status"     => self::METHOD_NOT_ALLOWED,
                    "methods"    => array_keys($tRoute)
                ];
            }
        }

        if ($this->routeDefinitions["variable"] === null) {
            $this->compile();
        }

        if (isset($this->routeDefinitions["variable"][$uMethod])) {
            foreach ($this->routeDefinitions["variable"][$uMethod] as $tVariableRoute) {
                if (preg_match($tVariableRoute["regex"], $uPathInfo, $tMatches) !== 1) {
                    continue;
                }

                list($tCallback, $tVariableNames) = $tVariableRoute["routeMap"][count($tMatches)];

                $tVariables = [];
                $tCount = 0;
                foreach ($tVariableNames as $tVariableName) {
                    $tVariables[$tVariableName] = $tMatches[++$tCount];
                }

                return [
                    "status"     => self::FOUND,
                    "callback"   => $tCallback,
                    "parameters" => $tVariables
                ];
            }
        }

        // Find allowed methods for this URI by matching against all other
        // HTTP methods as well
        $tAllowedMethods = [];
        foreach ($this->routeDefinitions["variable"] as $tCurrentMethod => $tVariableRouteSets) {
            foreach ($tVariableRouteSets as $tVariableRoute) {
                if (preg_match($tVariableRoute["regex"], $uPathInfo, $tMatches) !== 1) {
                    continue;
                }

                $tAllowedMethods[] = $tCurrentMethod;
            }
        }

        if (count($tAllowedMethods) > 0) {
            return [
                "status"     => self::METHOD_NOT_ALLOWED,
                "methods"    => $tAllowedMethods
            ];
        }

        // If there are no allowed methods the route simply does not exist
        return [
            "status"     => self::NOT_FOUND
        ];
    }
}
