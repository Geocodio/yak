import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments/{deployment}'
*/
export const show = (args: { deployment: string | number } | [deployment: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/deployments/{deployment}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments/{deployment}'
*/
show.url = (args: { deployment: string | number } | [deployment: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deployment: args }
    }

    if (Array.isArray(args)) {
        args = {
            deployment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        deployment: args.deployment,
    }

    return show.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments/{deployment}'
*/
show.get = (args: { deployment: string | number } | [deployment: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments/{deployment}'
*/
show.head = (args: { deployment: string | number } | [deployment: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Internal\DeploymentWakeController::__invoke
* @see app/Http/Controllers/Internal/DeploymentWakeController.php:21
* @route '/internal/deployments/wake'
*/
export const wake = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: wake.url(options),
    method: 'get',
})

wake.definition = {
    methods: ["get","head"],
    url: '/internal/deployments/wake',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\DeploymentWakeController::__invoke
* @see app/Http/Controllers/Internal/DeploymentWakeController.php:21
* @route '/internal/deployments/wake'
*/
wake.url = (options?: RouteQueryOptions) => {
    return wake.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\DeploymentWakeController::__invoke
* @see app/Http/Controllers/Internal/DeploymentWakeController.php:21
* @route '/internal/deployments/wake'
*/
wake.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: wake.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\DeploymentWakeController::__invoke
* @see app/Http/Controllers/Internal/DeploymentWakeController.php:21
* @route '/internal/deployments/wake'
*/
wake.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: wake.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Deployments\AuthBounceController::__invoke
* @see app/Http/Controllers/Deployments/AuthBounceController.php:22
* @route '/deployments/auth-bounce'
*/
export const authBounce = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: authBounce.url(options),
    method: 'get',
})

authBounce.definition = {
    methods: ["get","head"],
    url: '/deployments/auth-bounce',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Deployments\AuthBounceController::__invoke
* @see app/Http/Controllers/Deployments/AuthBounceController.php:22
* @route '/deployments/auth-bounce'
*/
authBounce.url = (options?: RouteQueryOptions) => {
    return authBounce.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\AuthBounceController::__invoke
* @see app/Http/Controllers/Deployments/AuthBounceController.php:22
* @route '/deployments/auth-bounce'
*/
authBounce.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: authBounce.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Deployments\AuthBounceController::__invoke
* @see app/Http/Controllers/Deployments/AuthBounceController.php:22
* @route '/deployments/auth-bounce'
*/
authBounce.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: authBounce.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Internal\DeploymentStatusController::__invoke
* @see app/Http/Controllers/Internal/DeploymentStatusController.php:12
* @route '/internal/deployments/status'
*/
export const status = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(options),
    method: 'get',
})

status.definition = {
    methods: ["get","head"],
    url: '/internal/deployments/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\DeploymentStatusController::__invoke
* @see app/Http/Controllers/Internal/DeploymentStatusController.php:12
* @route '/internal/deployments/status'
*/
status.url = (options?: RouteQueryOptions) => {
    return status.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\DeploymentStatusController::__invoke
* @see app/Http/Controllers/Internal/DeploymentStatusController.php:12
* @route '/internal/deployments/status'
*/
status.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\DeploymentStatusController::__invoke
* @see app/Http/Controllers/Internal/DeploymentStatusController.php:12
* @route '/internal/deployments/status'
*/
status.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: status.url(options),
    method: 'head',
})

const deployments = {
    show: Object.assign(show, show),
    wake: Object.assign(wake, wake),
    authBounce: Object.assign(authBounce, authBounce),
    status: Object.assign(status, status),
}

export default deployments