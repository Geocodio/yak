import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\DeploymentWakeController::__invoke
* @see app/Http/Controllers/Internal/DeploymentWakeController.php:21
* @route '/internal/deployments/wake'
*/
const DeploymentWakeController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: DeploymentWakeController.url(options),
    method: 'get',
})

DeploymentWakeController.definition = {
    methods: ["get","head"],
    url: '/internal/deployments/wake',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\DeploymentWakeController::__invoke
* @see app/Http/Controllers/Internal/DeploymentWakeController.php:21
* @route '/internal/deployments/wake'
*/
DeploymentWakeController.url = (options?: RouteQueryOptions) => {
    return DeploymentWakeController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\DeploymentWakeController::__invoke
* @see app/Http/Controllers/Internal/DeploymentWakeController.php:21
* @route '/internal/deployments/wake'
*/
DeploymentWakeController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: DeploymentWakeController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\DeploymentWakeController::__invoke
* @see app/Http/Controllers/Internal/DeploymentWakeController.php:21
* @route '/internal/deployments/wake'
*/
DeploymentWakeController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: DeploymentWakeController.url(options),
    method: 'head',
})

export default DeploymentWakeController