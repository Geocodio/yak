import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Api/App/Tasks/TaskListController.php:12
* @route '/api/app/v1/tasks'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/tasks',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Api/App/Tasks/TaskListController.php:12
* @route '/api/app/v1/tasks'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Api/App/Tasks/TaskListController.php:12
* @route '/api/app/v1/tasks'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Api/App/Tasks/TaskListController.php:12
* @route '/api/app/v1/tasks'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskController::show
* @see app/Http/Controllers/Api/App/Tasks/TaskController.php:13
* @route '/api/app/v1/tasks/{task}'
*/
export const show = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/tasks/{task}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskController::show
* @see app/Http/Controllers/Api/App/Tasks/TaskController.php:13
* @route '/api/app/v1/tasks/{task}'
*/
show.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskController::show
* @see app/Http/Controllers/Api/App/Tasks/TaskController.php:13
* @route '/api/app/v1/tasks/{task}'
*/
show.get = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskController::show
* @see app/Http/Controllers/Api/App/Tasks/TaskController.php:13
* @route '/api/app/v1/tasks/{task}'
*/
show.head = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::retry
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:16
* @route '/api/app/v1/tasks/{task}/retry'
*/
export const retry = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

retry.definition = {
    methods: ["post"],
    url: '/api/app/v1/tasks/{task}/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::retry
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:16
* @route '/api/app/v1/tasks/{task}/retry'
*/
retry.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return retry.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::retry
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:16
* @route '/api/app/v1/tasks/{task}/retry'
*/
retry.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::cancel
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:21
* @route '/api/app/v1/tasks/{task}/cancel'
*/
export const cancel = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

cancel.definition = {
    methods: ["post"],
    url: '/api/app/v1/tasks/{task}/cancel',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::cancel
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:21
* @route '/api/app/v1/tasks/{task}/cancel'
*/
cancel.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return cancel.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::cancel
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:21
* @route '/api/app/v1/tasks/{task}/cancel'
*/
cancel.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::rerunReview
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:29
* @route '/api/app/v1/tasks/{task}/rerun-review'
*/
export const rerunReview = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerunReview.url(args, options),
    method: 'post',
})

rerunReview.definition = {
    methods: ["post"],
    url: '/api/app/v1/tasks/{task}/rerun-review',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::rerunReview
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:29
* @route '/api/app/v1/tasks/{task}/rerun-review'
*/
rerunReview.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return rerunReview.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::rerunReview
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:29
* @route '/api/app/v1/tasks/{task}/rerun-review'
*/
rerunReview.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerunReview.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::retryRender
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:34
* @route '/api/app/v1/tasks/{task}/retry-render'
*/
export const retryRender = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retryRender.url(args, options),
    method: 'post',
})

retryRender.definition = {
    methods: ["post"],
    url: '/api/app/v1/tasks/{task}/retry-render',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::retryRender
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:34
* @route '/api/app/v1/tasks/{task}/retry-render'
*/
retryRender.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return retryRender.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::retryRender
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:34
* @route '/api/app/v1/tasks/{task}/retry-render'
*/
retryRender.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retryRender.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::reroute
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:39
* @route '/api/app/v1/tasks/{task}/reroute'
*/
export const reroute = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reroute.url(args, options),
    method: 'post',
})

reroute.definition = {
    methods: ["post"],
    url: '/api/app/v1/tasks/{task}/reroute',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::reroute
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:39
* @route '/api/app/v1/tasks/{task}/reroute'
*/
reroute.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return reroute.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Tasks\TaskActionController::reroute
* @see app/Http/Controllers/Api/App/Tasks/TaskActionController.php:39
* @route '/api/app/v1/tasks/{task}/reroute'
*/
reroute.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reroute.url(args, options),
    method: 'post',
})

const tasks = {
    index: Object.assign(index, index),
    show: Object.assign(show, show),
    retry: Object.assign(retry, retry),
    cancel: Object.assign(cancel, cancel),
    rerunReview: Object.assign(rerunReview, rerunReview),
    retryRender: Object.assign(retryRender, retryRender),
    reroute: Object.assign(reroute, reroute),
}

export default tasks