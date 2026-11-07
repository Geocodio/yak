import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\StreamController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/StreamController.php:30
* @route '/telemetry-ui/api/v2/stream/{signal}'
*/
const StreamController = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: StreamController.url(args, options),
    method: 'get',
})

StreamController.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/stream/{signal}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\StreamController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/StreamController.php:30
* @route '/telemetry-ui/api/v2/stream/{signal}'
*/
StreamController.url = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { signal: args }
    }

    if (Array.isArray(args)) {
        args = {
            signal: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        signal: args.signal,
    }

    return StreamController.definition.url
            .replace('{signal}', parsedArgs.signal.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\StreamController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/StreamController.php:30
* @route '/telemetry-ui/api/v2/stream/{signal}'
*/
StreamController.get = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: StreamController.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\StreamController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/StreamController.php:30
* @route '/telemetry-ui/api/v2/stream/{signal}'
*/
StreamController.head = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: StreamController.url(args, options),
    method: 'head',
})

export default StreamController