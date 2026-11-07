import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PageController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PageController.php:24
* @route '/telemetry-ui/api/v2/pages/{page}'
*/
const PageController = (args: { page: string | number } | [page: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PageController.url(args, options),
    method: 'get',
})

PageController.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/pages/{page}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PageController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PageController.php:24
* @route '/telemetry-ui/api/v2/pages/{page}'
*/
PageController.url = (args: { page: string | number } | [page: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { page: args }
    }

    if (Array.isArray(args)) {
        args = {
            page: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        page: args.page,
    }

    return PageController.definition.url
            .replace('{page}', parsedArgs.page.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PageController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PageController.php:24
* @route '/telemetry-ui/api/v2/pages/{page}'
*/
PageController.get = (args: { page: string | number } | [page: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PageController.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PageController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PageController.php:24
* @route '/telemetry-ui/api/v2/pages/{page}'
*/
PageController.head = (args: { page: string | number } | [page: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PageController.url(args, options),
    method: 'head',
})

export default PageController