import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\ObservationController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/ObservationController.php:16
* @route '/observations'
*/
const ObservationController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ObservationController.url(options),
    method: 'get',
})

ObservationController.definition = {
    methods: ["get","head"],
    url: '/observations',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ObservationController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/ObservationController.php:16
* @route '/observations'
*/
ObservationController.url = (options?: RouteQueryOptions) => {
    return ObservationController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ObservationController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/ObservationController.php:16
* @route '/observations'
*/
ObservationController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ObservationController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ObservationController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/ObservationController.php:16
* @route '/observations'
*/
ObservationController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ObservationController.url(options),
    method: 'head',
})

export default ObservationController