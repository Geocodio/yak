import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
import hibernation from './hibernation'
import share from './share'
/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:31
* @route '/deployments/{deployment}'
*/
export const show = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/deployments/{deployment}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:31
* @route '/deployments/{deployment}'
*/
show.url = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deployment: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { deployment: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            deployment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        deployment: typeof args.deployment === 'object'
        ? args.deployment.id
        : args.deployment,
    }

    return show.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:31
* @route '/deployments/{deployment}'
*/
show.get = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:31
* @route '/deployments/{deployment}'
*/
show.head = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::rebuild
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:51
* @route '/deployments/{deployment}/rebuild'
*/
export const rebuild = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rebuild.url(args, options),
    method: 'post',
})

rebuild.definition = {
    methods: ["post"],
    url: '/deployments/{deployment}/rebuild',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::rebuild
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:51
* @route '/deployments/{deployment}/rebuild'
*/
rebuild.url = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deployment: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { deployment: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            deployment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        deployment: typeof args.deployment === 'object'
        ? args.deployment.id
        : args.deployment,
    }

    return rebuild.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::rebuild
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:51
* @route '/deployments/{deployment}/rebuild'
*/
rebuild.post = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rebuild.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::destroy
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:58
* @route '/deployments/{deployment}'
*/
export const destroy = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/deployments/{deployment}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::destroy
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:58
* @route '/deployments/{deployment}'
*/
destroy.url = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deployment: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { deployment: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            deployment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        deployment: typeof args.deployment === 'object'
        ? args.deployment.id
        : args.deployment,
    }

    return destroy.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::destroy
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:58
* @route '/deployments/{deployment}'
*/
destroy.delete = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
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
    hibernation: Object.assign(hibernation, hibernation),
    rebuild: Object.assign(rebuild, rebuild),
    destroy: Object.assign(destroy, destroy),
    share: Object.assign(share, share),
    wake: Object.assign(wake, wake),
    authBounce: Object.assign(authBounce, authBounce),
    status: Object.assign(status, status),
}

export default deployments