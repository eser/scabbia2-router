<?php
/**
 * Scabbia2 PHP Framework Code
 * http://www.scabbiafw.com/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link        http://github.com/scabbiafw/scabbia2-fw for the canonical source repository
 * @copyright   2010-2015 Scabbia Framework Organization. (http://www.scabbiafw.com/)
 * @license     http://www.apache.org/licenses/LICENSE-2.0 - Apache License, Version 2.0
 */

namespace Scabbia\Router;

/**
 * Router
 *
 * @package     Scabbia\Router
 * @author      Eser Ozvataf <eser@sent.com>
 * @since       2.0.0
 *
 * Routing related code based on the nikic's FastRoute solution:
 * http://nikic.github.io/2014/02/18/Fast-request-routing-using-regular-expressions.html
 */
class Router
{
    /** @type string VARIABLE_REGEX Regex expression of variables */
    const VARIABLE_REGEX = <<<'REGEX'
~\{
    \s* ([a-zA-Z][a-zA-Z0-9_]*) \s*
    (?:
        : \s* ([^{}]*(?:\{(?-1)\}[^{}*])*)
    )?
\}~x
REGEX;

    /** @type string DEFAULT_DISPATCH_REGEX Regex expression of default dispatch */
    const DEFAULT_DISPATCH_REGEX = "[^/]+";

    /** @type int FOUND              route found */
    const FOUND = 0;
    /** @type int NOT_FOUND          route not found */
    const NOT_FOUND = 1;
    /** @type int METHOD_NOT_ALLOWED route method is not allowed */
    const METHOD_NOT_ALLOWED = 2;

    /** @type string FILTER_VALIDATE_BOOLEAN a symbolic constant for boolean validation */
    const APPROX_CHUNK_SIZE = 10;


    /** @type array route definitions */
    public $routes;


    /**
     * Initializes a router
     *
     * @param array|null  $uRoutes   routes
     *
     * @return Router
     */
    public function __construct($uRoutes = null)
    {
        if ($uRoutes !== null) {
            $this->routes = $uRoutes;
        } else {
            $this->routes = [
                "static" => [],
                "variable" => null,
                "named" => []
            ];
        }
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
        if (isset($this->routes["static"][$uPathInfo])) {
            $tRoute = $this->routes["static"][$uPathInfo];

            if (isset($tRoute[$uMethod])) {
                return [
                    "status"     => self::FOUND,
                    "callback"   => $tRoute[$uMethod],
                    "parameters" => []
                ];
            } elseif ($uMethod === "HEAD" && isset($tRoute["GET"])) {
                return [
                    "status"     => self::FOUND,
                    "callback"   => $tRoute["GET"],
                    "parameters" => []
                ];
            } else {
                return [
                    "status"     => self::METHOD_NOT_ALLOWED,
                    "methods"    => array_keys($tRoute)
                ];
            }
        }

        if ($this->routes["variable"] === null) {
            $this->compile();
        }

        if ($uMethod === "HEAD" && !isset($this->routes["variable"]["HEAD"])) {
            $tQueryMethod = "GET";
        } else {
            $tQueryMethod = $uMethod;
        }

        if (isset($this->routes["variable"][$tQueryMethod])) {
            foreach ($this->routes["variable"][$tQueryMethod] as $tVariableRoute) {
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
        foreach ($this->routes["variable"] as $tCurrentMethod => $tVariableRouteSets) {
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

    /**
     * Parses routes of the following form:
     * "/user/{name}/{id:[0-9]+}"
     *
     * @param string $uRoute route pattern
     *
     * @return array
     */
    public function parse($uRoute)
    {
        if (!preg_match_all(self::VARIABLE_REGEX, $uRoute, $tMatches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [$uRoute];
        }

        $tOffset = 0;
        $tRouteData = [];
        foreach ($tMatches as $tMatch) {
            if ($tMatch[0][1] > $tOffset) {
                $tRouteData[] = substr($uRoute, $tOffset, $tMatch[0][1] - $tOffset);
            }

            $tRouteData[] = [
                $tMatch[1][0],
                isset($tMatch[2]) ? trim($tMatch[2][0]) : self::DEFAULT_DISPATCH_REGEX
            ];

            $tOffset = $tMatch[0][1] + strlen($tMatch[0][0]);
        }

        if ($tOffset !== strlen($uRoute)) {
            $tRouteData[] = substr($uRoute, $tOffset);
        }

        return $tRouteData;
    }

    /**
     * Generates a path using named routes
     *
     * @param string $uName        name of route
     * @param array  $uParameters  parameters
     *
     * @return false|string
     */
    public function path($uName, array $uParameters = [])
    {
        if (!isset($this->routes["named"][$uName])) {
            return false;
        }

        $tNamedRoute = $this->routes["named"][$uName];
        $tLink = $tNamedRoute[0];
        foreach ($tNamedRoute[1] as $tParameter) {
            if (isset($uParameters[$tParameter])) {
                $tValue = $uParameters[$tParameter];
            } else {
                $tValue = "";
            }

            $tLink = str_replace("{{$tParameter}}", $tValue, $tLink);
        }

        return $tLink;
    }

    /**
     * Adds specified route
     *
     * @param string|array  $uMethods   http methods
     * @param string        $uRoute     route
     * @param callable      $uCallback  callback
     * @param string|null   $uName      name of route
     *
     * @return void
     */
    public function addRoute($uMethods, $uRoute, $uCallback, $uName = null)
    {
        $tRouteData = $this->parse($uRoute);
        $tMethods = (array)$uMethods;

        if (count($tRouteData) === 1 && is_string($tRouteData[0])) {
            $this->addStaticRoute($tMethods, $tRouteData, $uCallback, $uName);
        } else {
            $this->addVariableRoute($tMethods, $tRouteData, $uCallback, $uName);
        }
    }

    /**
     * Adds a static route
     *
     * @param array         $uMethods    http methods
     * @param array         $uRouteData  route data
     * @param callable      $uCallback   callback
     * @param string|null   $uName       name of route
     *
     * @throws UnexpectedValueException if an routing problem occurs
     * @return void
     */
    public function addStaticRoute(array $uMethods, $uRouteData, $uCallback, $uName = null)
    {
        $tRouteStr = $uRouteData[0];

        foreach ($uMethods as $tMethod) {
            if (isset($this->routes["static"][$tRouteStr][$tMethod])) {
                throw new UnexpectedValueException(sprintf(
                    "Cannot register two routes matching \"%s\" for method \"%s\"",
                    $tRouteStr,
                    $tMethod
                ));
            }
        }

        foreach ($uMethods as $tMethod) {
            if (isset($this->regexToRoutesMap[$tMethod])) {
                foreach ($this->regexToRoutesMap[$tMethod] as $tRoute) {
                    if (preg_match("~^{$tRoute["regex"]}$~", $tRouteStr) === 1) {
                        throw new UnexpectedValueException(sprintf(
                            "Static route \"%s\" is shadowed by previously defined variable " .
                            "route \"%s\" for method \"%s\"",
                            $tRouteStr,
                            $tRoute["regex"],
                            $tMethod
                        ));
                    }
                }
            }

            $this->routes["static"][$tRouteStr][$tMethod] = $uCallback;

            /*
            if ($uName !== null) {
                if (!isset($this->routes["named"][$tMethod])) {
                    $this->routes["named"][$tMethod] = [];
                }

                $this->routes["named"][$tMethod][$uName] = [$tRouteStr, []];
            }
            */
            if ($uName !== null && !isset($this->routes["named"][$uName])) {
                $this->routes["named"][$uName] = [$tRouteStr, []];
            }
        }
    }

    /**
     * Adds a variable route
     *
     * @param array         $uMethods    http method
     * @param array         $uRouteData  route data
     * @param callable      $uCallback   callback
     * @param string|null   $uName       name of route
     *
     * @throws UnexpectedValueException if an routing problem occurs
     * @return void
     */
    public function addVariableRoute(array $uMethods, $uRouteData, $uCallback, $uName = null)
    {
        $tRegex = "";
        $tReverseRegex = "";
        $tVariables = [];

        foreach ($uRouteData as $tPart) {
            if (is_string($tPart)) {
                $tRegex .= preg_quote($tPart, "~");
                $tReverseRegex .= preg_quote($tPart, "~");
                continue;
            }

            list($tVariableName, $tRegexPart) = $tPart;

            if (isset($tVariables[$tVariableName])) {
                throw new UnexpectedValueException(sprintf(
                    "Cannot use the same placeholder \"%s\" twice",
                    $tVariableName
                ));
            }

            $tVariables[$tVariableName] = $tVariableName;
            $tRegex .= "({$tRegexPart})";
            $tReverseRegex .= "{{$tVariableName}}";
        }

        foreach ($uMethods as $tMethod) {
            if (isset($this->regexToRoutesMap[$tMethod][$tRegex])) {
                throw new UnexpectedValueException(
                    sprintf("Cannot register two routes matching \"%s\" for method \"%s\"", $tRegex, $tMethod)
                );
            }
        }

        foreach ($uMethods as $tMethod) {
            if (!isset($this->regexToRoutesMap[$tMethod])) {
                $this->regexToRoutesMap[$tMethod] = [];
            }

            $this->regexToRoutesMap[$tMethod][$tRegex] = [
                // "method"    => $tMethod,
                "callback"  => $uCallback,
                "regex"     => $tRegex,
                "variables" => $tVariables
            ];

            /*
            if ($uName !== null) {
                if (!isset($this->routes["named"][$tMethod])) {
                    $this->routes["named"][$tMethod] = [];
                }

                $this->routes["named"][$tMethod][$uName] = [$tRegex, $tVariables];
            }
            */
            if ($uName !== null && !isset($this->routes["named"][$uName])) {
                $this->routes["named"][$uName] = [$tReverseRegex, array_values($tVariables)];
            }
        }
    }

    /**
     * Combines all route data
     *
     * @return void
     */
    public function compile()
    {
        $this->routes["variable"] = [];
        foreach ($this->regexToRoutesMap as $tMethod => $tRegexToRoutesMapOfMethod) {
            $tRegexToRoutesMapOfMethodCount = count($tRegexToRoutesMapOfMethod);

            $tNumParts = max(1, round($tRegexToRoutesMapOfMethodCount / self::APPROX_CHUNK_SIZE));
            $tChunkSize = ceil($tRegexToRoutesMapOfMethodCount / $tNumParts);

            $tChunks = array_chunk($tRegexToRoutesMapOfMethod, $tChunkSize, true);
            $this->routes["variable"][$tMethod] = array_map([$this, "processChunk"], $tChunks);
        }
    }

    /**
     * Returns route information in order to store it
     *
     * @return array
     */
    public function save()
    {
        if ($this->routes["variable"] === null) {
            $this->compile();
        }

        return $this->routes;
    }

    /**
     * Splits variable routes into chunks
     *
     * @param array $uRegexToRoutesMap route definitions
     *
     * @return array chunked
     */
    protected function processChunk(array $uRegexToRoutesMap)
    {
        $tRouteMap = [];
        $tRegexes = [];
        $tNumGroups = 0;

        foreach ($uRegexToRoutesMap as $tRegex => $tRoute) {
            $tNumVariables = count($tRoute["variables"]);
            $tNumGroups = max($tNumGroups, $tNumVariables);

            $tRegexes[] = $tRegex . str_repeat("()", $tNumGroups - $tNumVariables);
            $tRouteMap[$tNumGroups + 1] = [$tRoute["callback"], $tRoute["variables"]];

            ++$tNumGroups;
        }

        return [
            "regex"    => "~^(?|" . implode("|", $tRegexes) . ")$~",
            "routeMap" => $tRouteMap
        ];
    }
}
