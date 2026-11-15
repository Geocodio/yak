import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::approve
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:25
* @route '/tasks/{task}/co-owner/approve'
*/
export const approve = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: approve.url(args, options),
    method: 'post',
})

approve.definition = {
    methods: ["post"],
    url: '/tasks/{task}/co-owner/approve',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::approve
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:25
* @route '/tasks/{task}/co-owner/approve'
*/
approve.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return approve.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::approve
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:25
* @route '/tasks/{task}/co-owner/approve'
*/
approve.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: approve.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::requestChanges
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:34
* @route '/tasks/{task}/co-owner/request-changes'
*/
export const requestChanges = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: requestChanges.url(args, options),
    method: 'post',
})

requestChanges.definition = {
    methods: ["post"],
    url: '/tasks/{task}/co-owner/request-changes',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::requestChanges
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:34
* @route '/tasks/{task}/co-owner/request-changes'
*/
requestChanges.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return requestChanges.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::requestChanges
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:34
* @route '/tasks/{task}/co-owner/request-changes'
*/
requestChanges.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: requestChanges.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::reject
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:43
* @route '/tasks/{task}/co-owner/reject'
*/
export const reject = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reject.url(args, options),
    method: 'post',
})

reject.definition = {
    methods: ["post"],
    url: '/tasks/{task}/co-owner/reject',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::reject
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:43
* @route '/tasks/{task}/co-owner/reject'
*/
reject.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return reject.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::reject
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:43
* @route '/tasks/{task}/co-owner/reject'
*/
reject.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reject.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::withdraw
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:52
* @route '/tasks/{task}/co-owner/withdraw'
*/
export const withdraw = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: withdraw.url(args, options),
    method: 'post',
})

withdraw.definition = {
    methods: ["post"],
    url: '/tasks/{task}/co-owner/withdraw',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::withdraw
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:52
* @route '/tasks/{task}/co-owner/withdraw'
*/
withdraw.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return withdraw.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::withdraw
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:52
* @route '/tasks/{task}/co-owner/withdraw'
*/
withdraw.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: withdraw.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::checkAccess
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:61
* @route '/tasks/{task}/co-owner/check-access'
*/
export const checkAccess = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkAccess.url(args, options),
    method: 'post',
})

checkAccess.definition = {
    methods: ["post"],
    url: '/tasks/{task}/co-owner/check-access',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::checkAccess
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:61
* @route '/tasks/{task}/co-owner/check-access'
*/
checkAccess.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return checkAccess.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\CoOwnerDecisionController::checkAccess
* @see app/Http/Controllers/Tasks/CoOwnerDecisionController.php:61
* @route '/tasks/{task}/co-owner/check-access'
*/
checkAccess.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkAccess.url(args, options),
    method: 'post',
})

const CoOwnerDecisionController = { approve, requestChanges, reject, withdraw, checkAccess }

export default CoOwnerDecisionController