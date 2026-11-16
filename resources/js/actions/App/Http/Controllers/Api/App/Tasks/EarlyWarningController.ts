import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Tasks\EarlyWarningController::candidates
* @see app/Http/Controllers/Api/App/Tasks/EarlyWarningController.php:26
* @route '/api/app/v1/tasks/{task}/co-owner/candidates'
*/
export const candidates = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: candidates.url(args, options),
    method: 'get',
})

candidates.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/tasks/{task}/co-owner/candidates',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Tasks\EarlyWarningController::candidates
* @see app/Http/Controllers/Api/App/Tasks/EarlyWarningController.php:26
* @route '/api/app/v1/tasks/{task}/co-owner/candidates'
*/
candidates.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { task: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return candidates.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Tasks\EarlyWarningController::candidates
* @see app/Http/Controllers/Api/App/Tasks/EarlyWarningController.php:26
* @route '/api/app/v1/tasks/{task}/co-owner/candidates'
*/
candidates.get = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: candidates.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\EarlyWarningController::candidates
* @see app/Http/Controllers/Api/App/Tasks/EarlyWarningController.php:26
* @route '/api/app/v1/tasks/{task}/co-owner/candidates'
*/
candidates.head = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: candidates.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\EarlyWarningController::tag
* @see app/Http/Controllers/Api/App/Tasks/EarlyWarningController.php:31
* @route '/api/app/v1/tasks/{task}/co-owner/tag'
*/
export const tag = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: tag.url(args, options),
    method: 'post',
})

tag.definition = {
    methods: ["post"],
    url: '/api/app/v1/tasks/{task}/co-owner/tag',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Tasks\EarlyWarningController::tag
* @see app/Http/Controllers/Api/App/Tasks/EarlyWarningController.php:31
* @route '/api/app/v1/tasks/{task}/co-owner/tag'
*/
tag.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { task: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return tag.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Tasks\EarlyWarningController::tag
* @see app/Http/Controllers/Api/App/Tasks/EarlyWarningController.php:31
* @route '/api/app/v1/tasks/{task}/co-owner/tag'
*/
tag.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: tag.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\EarlyWarningController::decline
* @see app/Http/Controllers/Api/App/Tasks/EarlyWarningController.php:38
* @route '/api/app/v1/tasks/{task}/co-owner/decline'
*/
export const decline = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: decline.url(args, options),
    method: 'post',
})

decline.definition = {
    methods: ["post"],
    url: '/api/app/v1/tasks/{task}/co-owner/decline',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Tasks\EarlyWarningController::decline
* @see app/Http/Controllers/Api/App/Tasks/EarlyWarningController.php:38
* @route '/api/app/v1/tasks/{task}/co-owner/decline'
*/
decline.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { task: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return decline.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Tasks\EarlyWarningController::decline
* @see app/Http/Controllers/Api/App/Tasks/EarlyWarningController.php:38
* @route '/api/app/v1/tasks/{task}/co-owner/decline'
*/
decline.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: decline.url(args, options),
    method: 'post',
})

const EarlyWarningController = { candidates, tag, decline }

export default EarlyWarningController