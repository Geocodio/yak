import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::index
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:28
* @route '/telemetry-ui/api/v2/monitors'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/monitors',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::index
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:28
* @route '/telemetry-ui/api/v2/monitors'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::index
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:28
* @route '/telemetry-ui/api/v2/monitors'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::index
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:28
* @route '/telemetry-ui/api/v2/monitors'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::store
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:45
* @route '/telemetry-ui/api/v2/monitors'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/telemetry-ui/api/v2/monitors',
} satisfies RouteDefinition<["post"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::store
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:45
* @route '/telemetry-ui/api/v2/monitors'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::store
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:45
* @route '/telemetry-ui/api/v2/monitors'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::destroy
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:79
* @route '/telemetry-ui/api/v2/monitors/{service}/{id}'
*/
export const destroy = (args: { service: string | number, id: string | number } | [service: string | number, id: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/telemetry-ui/api/v2/monitors/{service}/{id}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::destroy
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:79
* @route '/telemetry-ui/api/v2/monitors/{service}/{id}'
*/
destroy.url = (args: { service: string | number, id: string | number } | [service: string | number, id: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            service: args[0],
            id: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        service: args.service,
        id: args.id,
    }

    return destroy.definition.url
            .replace('{service}', parsedArgs.service.toString())
            .replace('{id}', parsedArgs.id.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::destroy
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:79
* @route '/telemetry-ui/api/v2/monitors/{service}/{id}'
*/
destroy.delete = (args: { service: string | number, id: string | number } | [service: string | number, id: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const MonitorsController = { index, store, destroy }

export default MonitorsController