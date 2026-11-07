import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::show
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:24
* @route '/telemetry-ui/api/v2/issues/{id}'
*/
export const show = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/issues/{id}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::show
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:24
* @route '/telemetry-ui/api/v2/issues/{id}'
*/
show.url = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { id: args }
    }

    if (Array.isArray(args)) {
        args = {
            id: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        id: args.id,
    }

    return show.definition.url
            .replace('{id}', parsedArgs.id.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::show
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:24
* @route '/telemetry-ui/api/v2/issues/{id}'
*/
show.get = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::show
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:24
* @route '/telemetry-ui/api/v2/issues/{id}'
*/
show.head = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::store
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:39
* @route '/telemetry-ui/api/v2/issues'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/telemetry-ui/api/v2/issues',
} satisfies RouteDefinition<["post"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::store
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:39
* @route '/telemetry-ui/api/v2/issues'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::store
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:39
* @route '/telemetry-ui/api/v2/issues'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

const IssueController = { show, store }

export default IssueController