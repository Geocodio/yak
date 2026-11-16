import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\ObservationController::__invoke
* @see app/Http/Controllers/Api/App/ObservationController.php:14
* @route '/api/app/v1/observations'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/observations',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\ObservationController::__invoke
* @see app/Http/Controllers/Api/App/ObservationController.php:14
* @route '/api/app/v1/observations'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\ObservationController::__invoke
* @see app/Http/Controllers/Api/App/ObservationController.php:14
* @route '/api/app/v1/observations'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\ObservationController::__invoke
* @see app/Http/Controllers/Api/App/ObservationController.php:14
* @route '/api/app/v1/observations'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const observations = {
    index: Object.assign(index, index),
}

export default observations