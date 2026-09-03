import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\CostDashboardController::__invoke
* @see app/Http/Controllers/CostDashboardController.php:21
* @route '/costs'
*/
const CostDashboardController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CostDashboardController.url(options),
    method: 'get',
})

CostDashboardController.definition = {
    methods: ["get","head"],
    url: '/costs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\CostDashboardController::__invoke
* @see app/Http/Controllers/CostDashboardController.php:21
* @route '/costs'
*/
CostDashboardController.url = (options?: RouteQueryOptions) => {
    return CostDashboardController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\CostDashboardController::__invoke
* @see app/Http/Controllers/CostDashboardController.php:21
* @route '/costs'
*/
CostDashboardController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CostDashboardController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\CostDashboardController::__invoke
* @see app/Http/Controllers/CostDashboardController.php:21
* @route '/costs'
*/
CostDashboardController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: CostDashboardController.url(options),
    method: 'head',
})

export default CostDashboardController