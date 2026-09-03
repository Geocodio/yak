import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
import setupCard from './setup-card'
import messages from './messages'
/**
* @see \App\Http\Controllers\Tasks\StoreTaskController::__invoke
* @see app/Http/Controllers/Tasks/StoreTaskController.php:18
* @route '/tasks'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/tasks',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\StoreTaskController::__invoke
* @see app/Http/Controllers/Tasks/StoreTaskController.php:18
* @route '/tasks'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\StoreTaskController::__invoke
* @see app/Http/Controllers/Tasks/StoreTaskController.php:18
* @route '/tasks'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Tasks\TaskController::show
* @see app/Http/Controllers/Tasks/TaskController.php:16
* @route '/tasks/{task}'
*/
export const show = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/tasks/{task}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Tasks\TaskController::show
* @see app/Http/Controllers/Tasks/TaskController.php:16
* @route '/tasks/{task}'
*/
show.url = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { task: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
    }

    return show.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskController::show
* @see app/Http/Controllers/Tasks/TaskController.php:16
* @route '/tasks/{task}'
*/
show.get = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Tasks\TaskController::show
* @see app/Http/Controllers/Tasks/TaskController.php:16
* @route '/tasks/{task}'
*/
show.head = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::retry
* @see app/Http/Controllers/Tasks/TaskActionController.php:30
* @route '/tasks/{task}/retry'
*/
export const retry = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

retry.definition = {
    methods: ["post"],
    url: '/tasks/{task}/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::retry
* @see app/Http/Controllers/Tasks/TaskActionController.php:30
* @route '/tasks/{task}/retry'
*/
retry.url = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { task: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
    }

    return retry.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::retry
* @see app/Http/Controllers/Tasks/TaskActionController.php:30
* @route '/tasks/{task}/retry'
*/
retry.post = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::cancel
* @see app/Http/Controllers/Tasks/TaskActionController.php:62
* @route '/tasks/{task}/cancel'
*/
export const cancel = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

cancel.definition = {
    methods: ["post"],
    url: '/tasks/{task}/cancel',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::cancel
* @see app/Http/Controllers/Tasks/TaskActionController.php:62
* @route '/tasks/{task}/cancel'
*/
cancel.url = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { task: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
    }

    return cancel.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::cancel
* @see app/Http/Controllers/Tasks/TaskActionController.php:62
* @route '/tasks/{task}/cancel'
*/
cancel.post = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::rerunReview
* @see app/Http/Controllers/Tasks/TaskActionController.php:110
* @route '/tasks/{task}/rerun-review'
*/
export const rerunReview = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerunReview.url(args, options),
    method: 'post',
})

rerunReview.definition = {
    methods: ["post"],
    url: '/tasks/{task}/rerun-review',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::rerunReview
* @see app/Http/Controllers/Tasks/TaskActionController.php:110
* @route '/tasks/{task}/rerun-review'
*/
rerunReview.url = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { task: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
    }

    return rerunReview.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::rerunReview
* @see app/Http/Controllers/Tasks/TaskActionController.php:110
* @route '/tasks/{task}/rerun-review'
*/
rerunReview.post = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerunReview.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::retryRender
* @see app/Http/Controllers/Tasks/TaskActionController.php:168
* @route '/tasks/{task}/retry-render'
*/
export const retryRender = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retryRender.url(args, options),
    method: 'post',
})

retryRender.definition = {
    methods: ["post"],
    url: '/tasks/{task}/retry-render',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::retryRender
* @see app/Http/Controllers/Tasks/TaskActionController.php:168
* @route '/tasks/{task}/retry-render'
*/
retryRender.url = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { task: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
    }

    return retryRender.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::retryRender
* @see app/Http/Controllers/Tasks/TaskActionController.php:168
* @route '/tasks/{task}/retry-render'
*/
retryRender.post = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retryRender.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::reroute
* @see app/Http/Controllers/Tasks/TaskActionController.php:181
* @route '/tasks/{task}/reroute'
*/
export const reroute = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reroute.url(args, options),
    method: 'post',
})

reroute.definition = {
    methods: ["post"],
    url: '/tasks/{task}/reroute',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::reroute
* @see app/Http/Controllers/Tasks/TaskActionController.php:181
* @route '/tasks/{task}/reroute'
*/
reroute.url = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { task: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
    }

    return reroute.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskActionController::reroute
* @see app/Http/Controllers/Tasks/TaskActionController.php:181
* @route '/tasks/{task}/reroute'
*/
reroute.post = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reroute.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:16
* @route '/tasks/{task}/re-request-review'
*/
export const reRequestReview = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reRequestReview.url(args, options),
    method: 'post',
})

reRequestReview.definition = {
    methods: ["post"],
    url: '/tasks/{task}/re-request-review',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:16
* @route '/tasks/{task}/re-request-review'
*/
reRequestReview.url = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { task: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
    }

    return reRequestReview.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:16
* @route '/tasks/{task}/re-request-review'
*/
reRequestReview.post = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reRequestReview.url(args, options),
    method: 'post',
})

const tasks = {
    store: Object.assign(store, store),
    setupCard: Object.assign(setupCard, setupCard),
    show: Object.assign(show, show),
    retry: Object.assign(retry, retry),
    cancel: Object.assign(cancel, cancel),
    rerunReview: Object.assign(rerunReview, rerunReview),
    retryRender: Object.assign(retryRender, retryRender),
    reroute: Object.assign(reroute, reroute),
    messages: Object.assign(messages, messages),
    reRequestReview: Object.assign(reRequestReview, reRequestReview),
}

export default tasks