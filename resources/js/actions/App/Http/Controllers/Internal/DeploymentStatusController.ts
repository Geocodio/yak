import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\DeploymentStatusController::__invoke
* @see app/Http/Controllers/Internal/DeploymentStatusController.php:12
* @route '/internal/deployments/status'
*/
const DeploymentStatusController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: DeploymentStatusController.url(options),
    method: 'get',
})

DeploymentStatusController.definition = {
    methods: ["get","head"],
    url: '/internal/deployments/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\DeploymentStatusController::__invoke
* @see app/Http/Controllers/Internal/DeploymentStatusController.php:12
* @route '/internal/deployments/status'
*/
DeploymentStatusController.url = (options?: RouteQueryOptions) => {
    return DeploymentStatusController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\DeploymentStatusController::__invoke
* @see app/Http/Controllers/Internal/DeploymentStatusController.php:12
* @route '/internal/deployments/status'
*/
DeploymentStatusController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: DeploymentStatusController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\DeploymentStatusController::__invoke
* @see app/Http/Controllers/Internal/DeploymentStatusController.php:12
* @route '/internal/deployments/status'
*/
DeploymentStatusController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: DeploymentStatusController.url(options),
    method: 'head',
})

export default DeploymentStatusController