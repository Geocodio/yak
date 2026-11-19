import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::candidates
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:66
* @route '/api/app/v1/threads/{thread}/co-owner/candidates'
*/
export const candidates = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: candidates.url(args, options),
    method: 'get',
})

candidates.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads/{thread}/co-owner/candidates',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::candidates
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:66
* @route '/api/app/v1/threads/{thread}/co-owner/candidates'
*/
candidates.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return candidates.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::candidates
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:66
* @route '/api/app/v1/threads/{thread}/co-owner/candidates'
*/
candidates.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: candidates.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::candidates
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:66
* @route '/api/app/v1/threads/{thread}/co-owner/candidates'
*/
candidates.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: candidates.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::approve
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:26
* @route '/api/app/v1/threads/{thread}/co-owner/approve'
*/
export const approve = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: approve.url(args, options),
    method: 'post',
})

approve.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/co-owner/approve',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::approve
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:26
* @route '/api/app/v1/threads/{thread}/co-owner/approve'
*/
approve.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return approve.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::approve
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:26
* @route '/api/app/v1/threads/{thread}/co-owner/approve'
*/
approve.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: approve.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::requestChanges
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:31
* @route '/api/app/v1/threads/{thread}/co-owner/request-changes'
*/
export const requestChanges = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: requestChanges.url(args, options),
    method: 'post',
})

requestChanges.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/co-owner/request-changes',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::requestChanges
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:31
* @route '/api/app/v1/threads/{thread}/co-owner/request-changes'
*/
requestChanges.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return requestChanges.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::requestChanges
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:31
* @route '/api/app/v1/threads/{thread}/co-owner/request-changes'
*/
requestChanges.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: requestChanges.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::reject
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:36
* @route '/api/app/v1/threads/{thread}/co-owner/reject'
*/
export const reject = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reject.url(args, options),
    method: 'post',
})

reject.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/co-owner/reject',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::reject
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:36
* @route '/api/app/v1/threads/{thread}/co-owner/reject'
*/
reject.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return reject.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::reject
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:36
* @route '/api/app/v1/threads/{thread}/co-owner/reject'
*/
reject.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reject.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::withdraw
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:41
* @route '/api/app/v1/threads/{thread}/co-owner/withdraw'
*/
export const withdraw = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: withdraw.url(args, options),
    method: 'post',
})

withdraw.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/co-owner/withdraw',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::withdraw
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:41
* @route '/api/app/v1/threads/{thread}/co-owner/withdraw'
*/
withdraw.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return withdraw.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::withdraw
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:41
* @route '/api/app/v1/threads/{thread}/co-owner/withdraw'
*/
withdraw.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: withdraw.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::checkAccess
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:46
* @route '/api/app/v1/threads/{thread}/co-owner/check-access'
*/
export const checkAccess = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkAccess.url(args, options),
    method: 'post',
})

checkAccess.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/co-owner/check-access',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::checkAccess
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:46
* @route '/api/app/v1/threads/{thread}/co-owner/check-access'
*/
checkAccess.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return checkAccess.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::checkAccess
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:46
* @route '/api/app/v1/threads/{thread}/co-owner/check-access'
*/
checkAccess.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkAccess.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::tag
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:56
* @route '/api/app/v1/threads/{thread}/co-owner/tag'
*/
export const tag = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: tag.url(args, options),
    method: 'post',
})

tag.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/co-owner/tag',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::tag
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:56
* @route '/api/app/v1/threads/{thread}/co-owner/tag'
*/
tag.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return tag.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::tag
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:56
* @route '/api/app/v1/threads/{thread}/co-owner/tag'
*/
tag.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: tag.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::decline
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:61
* @route '/api/app/v1/threads/{thread}/co-owner/decline'
*/
export const decline = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: decline.url(args, options),
    method: 'post',
})

decline.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/co-owner/decline',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::decline
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:61
* @route '/api/app/v1/threads/{thread}/co-owner/decline'
*/
decline.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return decline.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController::decline
* @see app/Http/Controllers/Api/App/Threads/ThreadCoOwnerController.php:61
* @route '/api/app/v1/threads/{thread}/co-owner/decline'
*/
decline.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: decline.url(args, options),
    method: 'post',
})

const coOwner = {
    candidates: Object.assign(candidates, candidates),
    approve: Object.assign(approve, approve),
    requestChanges: Object.assign(requestChanges, requestChanges),
    reject: Object.assign(reject, reject),
    withdraw: Object.assign(withdraw, withdraw),
    checkAccess: Object.assign(checkAccess, checkAccess),
    tag: Object.assign(tag, tag),
    decline: Object.assign(decline, decline),
}

export default coOwner