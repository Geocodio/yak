import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\ObservationController::__invoke
* @see app/Http/Controllers/Api/App/ObservationController.php:14
* @route '/api/app/v1/observations'
*/
const ObservationController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ObservationController.url(options),
    method: 'get',
})

ObservationController.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/observations',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\ObservationController::__invoke
* @see app/Http/Controllers/Api/App/ObservationController.php:14
* @route '/api/app/v1/observations'
*/
ObservationController.url = (options?: RouteQueryOptions) => {
    return ObservationController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\ObservationController::__invoke
* @see app/Http/Controllers/Api/App/ObservationController.php:14
* @route '/api/app/v1/observations'
*/
ObservationController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ObservationController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\ObservationController::__invoke
* @see app/Http/Controllers/Api/App/ObservationController.php:14
* @route '/api/app/v1/observations'
*/
ObservationController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ObservationController.url(options),
    method: 'head',
})

export default ObservationController