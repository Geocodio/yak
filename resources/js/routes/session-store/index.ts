import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\SessionStoreController::append
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:21
* @route '/internal/session-store/{thread}/append'
*/
export const append = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: append.url(args, options),
    method: 'post',
})

append.definition = {
    methods: ["post"],
    url: '/internal/session-store/{thread}/append',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::append
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:21
* @route '/internal/session-store/{thread}/append'
*/
append.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return append.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::append
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:21
* @route '/internal/session-store/{thread}/append'
*/
append.post = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: append.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::length
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:56
* @route '/internal/session-store/{thread}/length'
*/
export const length = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: length.url(args, options),
    method: 'get',
})

length.definition = {
    methods: ["get","head"],
    url: '/internal/session-store/{thread}/length',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::length
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:56
* @route '/internal/session-store/{thread}/length'
*/
length.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return length.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::length
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:56
* @route '/internal/session-store/{thread}/length'
*/
length.get = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: length.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::length
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:56
* @route '/internal/session-store/{thread}/length'
*/
length.head = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: length.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::checkpoint
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:63
* @route '/internal/session-store/{thread}/checkpoint'
*/
export const checkpoint = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkpoint.url(args, options),
    method: 'post',
})

checkpoint.definition = {
    methods: ["post"],
    url: '/internal/session-store/{thread}/checkpoint',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::checkpoint
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:63
* @route '/internal/session-store/{thread}/checkpoint'
*/
checkpoint.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return checkpoint.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::checkpoint
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:63
* @route '/internal/session-store/{thread}/checkpoint'
*/
checkpoint.post = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkpoint.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::file
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:81
* @route '/internal/session-store/{thread}/file'
*/
export const file = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: file.url(args, options),
    method: 'put',
})

file.definition = {
    methods: ["put"],
    url: '/internal/session-store/{thread}/file',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::file
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:81
* @route '/internal/session-store/{thread}/file'
*/
file.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return file.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::file
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:81
* @route '/internal/session-store/{thread}/file'
*/
file.put = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: file.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::read
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:115
* @route '/internal/session-store/{thread}/read'
*/
export const read = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: read.url(args, options),
    method: 'get',
})

read.definition = {
    methods: ["get","head"],
    url: '/internal/session-store/{thread}/read',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::read
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:115
* @route '/internal/session-store/{thread}/read'
*/
read.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return read.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::read
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:115
* @route '/internal/session-store/{thread}/read'
*/
read.get = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: read.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::read
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:115
* @route '/internal/session-store/{thread}/read'
*/
read.head = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: read.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::files
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:108
* @route '/internal/session-store/{thread}/files'
*/
export const files = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: files.url(args, options),
    method: 'get',
})

files.definition = {
    methods: ["get","head"],
    url: '/internal/session-store/{thread}/files',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::files
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:108
* @route '/internal/session-store/{thread}/files'
*/
files.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return files.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::files
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:108
* @route '/internal/session-store/{thread}/files'
*/
files.get = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: files.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::files
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:108
* @route '/internal/session-store/{thread}/files'
*/
files.head = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: files.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::tree
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:135
* @route '/internal/session-store/{thread}/tree'
*/
export const tree = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: tree.url(args, options),
    method: 'get',
})

tree.definition = {
    methods: ["get","head"],
    url: '/internal/session-store/{thread}/tree',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::tree
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:135
* @route '/internal/session-store/{thread}/tree'
*/
tree.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return tree.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::tree
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:135
* @route '/internal/session-store/{thread}/tree'
*/
tree.get = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: tree.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\SessionStoreController::tree
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SessionStoreController.php:135
* @route '/internal/session-store/{thread}/tree'
*/
tree.head = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: tree.url(args, options),
    method: 'head',
})

const sessionStore = {
    append: Object.assign(append, append),
    length: Object.assign(length, length),
    checkpoint: Object.assign(checkpoint, checkpoint),
    file: Object.assign(file, file),
    read: Object.assign(read, read),
    files: Object.assign(files, files),
    tree: Object.assign(tree, tree),
}

export default sessionStore