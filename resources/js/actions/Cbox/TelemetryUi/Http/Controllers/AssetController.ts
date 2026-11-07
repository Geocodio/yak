import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\AssetController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/AssetController.php:27
* @route '/telemetry-ui/build/{path}'
*/
const AssetController = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AssetController.url(args, options),
    method: 'get',
})

AssetController.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/build/{path}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\AssetController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/AssetController.php:27
* @route '/telemetry-ui/build/{path}'
*/
AssetController.url = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { path: args }
    }

    if (Array.isArray(args)) {
        args = {
            path: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        path: args.path,
    }

    return AssetController.definition.url
            .replace('{path}', parsedArgs.path.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\AssetController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/AssetController.php:27
* @route '/telemetry-ui/build/{path}'
*/
AssetController.get = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AssetController.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\AssetController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/AssetController.php:27
* @route '/telemetry-ui/build/{path}'
*/
AssetController.head = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: AssetController.url(args, options),
    method: 'head',
})

export default AssetController