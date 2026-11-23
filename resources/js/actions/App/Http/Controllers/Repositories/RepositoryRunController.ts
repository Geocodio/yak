import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::show
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs'
*/
const showdc1a39dd1a88fac65b68a23a15f190a6 = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showdc1a39dd1a88fac65b68a23a15f190a6.url(args, options),
    method: 'get',
})

showdc1a39dd1a88fac65b68a23a15f190a6.definition = {
    methods: ["get","head"],
    url: '/repos/{repository}/runs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::show
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs'
*/
showdc1a39dd1a88fac65b68a23a15f190a6.url = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { repository: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'slug' in args) {
        args = { repository: args.slug }
    }

    if (Array.isArray(args)) {
        args = {
            repository: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repository: typeof args.repository === 'object'
        ? args.repository.slug
        : args.repository,
    }

    return showdc1a39dd1a88fac65b68a23a15f190a6.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::show
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs'
*/
showdc1a39dd1a88fac65b68a23a15f190a6.get = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showdc1a39dd1a88fac65b68a23a15f190a6.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::show
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs'
*/
showdc1a39dd1a88fac65b68a23a15f190a6.head = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: showdc1a39dd1a88fac65b68a23a15f190a6.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::show
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs/{task}'
*/
const show1be6cd9d1b44c7c8368b47c6c52615fe = (args: { repository: string | { slug: string }, task: string | { public_id: string } } | [repository: string | { slug: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show1be6cd9d1b44c7c8368b47c6c52615fe.url(args, options),
    method: 'get',
})

show1be6cd9d1b44c7c8368b47c6c52615fe.definition = {
    methods: ["get","head"],
    url: '/repos/{repository}/runs/{task}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::show
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs/{task}'
*/
show1be6cd9d1b44c7c8368b47c6c52615fe.url = (args: { repository: string | { slug: string }, task: string | { public_id: string } } | [repository: string | { slug: string }, task: string | { public_id: string } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            repository: args[0],
            task: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repository: typeof args.repository === 'object'
        ? args.repository.slug
        : args.repository,
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return show1be6cd9d1b44c7c8368b47c6c52615fe.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::show
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs/{task}'
*/
show1be6cd9d1b44c7c8368b47c6c52615fe.get = (args: { repository: string | { slug: string }, task: string | { public_id: string } } | [repository: string | { slug: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show1be6cd9d1b44c7c8368b47c6c52615fe.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::show
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs/{task}'
*/
show1be6cd9d1b44c7c8368b47c6c52615fe.head = (args: { repository: string | { slug: string }, task: string | { public_id: string } } | [repository: string | { slug: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show1be6cd9d1b44c7c8368b47c6c52615fe.url(args, options),
    method: 'head',
})

/**
* Multiple routes resolve to \App\Http\Controllers\Repositories\RepositoryRunController::show, so this export is a
* dictionary keyed by URI rather than a callable. Call a specific route with `show['<uri>'](...)`,
* or import the route by name from your generated `routes/` directory.
*/
export const show = {
    '/repos/{repository}/runs': showdc1a39dd1a88fac65b68a23a15f190a6,
    '/repos/{repository}/runs/{task}': show1be6cd9d1b44c7c8368b47c6c52615fe,
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::log
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:63
* @route '/repos/{repository}/runs/{task}/log'
*/
export const log = (args: { repository: string | { slug: string }, task: string | { public_id: string } } | [repository: string | { slug: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(args, options),
    method: 'get',
})

log.definition = {
    methods: ["get","head"],
    url: '/repos/{repository}/runs/{task}/log',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::log
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:63
* @route '/repos/{repository}/runs/{task}/log'
*/
log.url = (args: { repository: string | { slug: string }, task: string | { public_id: string } } | [repository: string | { slug: string }, task: string | { public_id: string } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            repository: args[0],
            task: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repository: typeof args.repository === 'object'
        ? args.repository.slug
        : args.repository,
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return log.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::log
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:63
* @route '/repos/{repository}/runs/{task}/log'
*/
log.get = (args: { repository: string | { slug: string }, task: string | { public_id: string } } | [repository: string | { slug: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::log
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:63
* @route '/repos/{repository}/runs/{task}/log'
*/
log.head = (args: { repository: string | { slug: string }, task: string | { public_id: string } } | [repository: string | { slug: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: log.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::retry
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:76
* @route '/repos/{repository}/runs/{task}/retry'
*/
export const retry = (args: { repository: string | { slug: string }, task: string | { public_id: string } } | [repository: string | { slug: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

retry.definition = {
    methods: ["post"],
    url: '/repos/{repository}/runs/{task}/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::retry
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:76
* @route '/repos/{repository}/runs/{task}/retry'
*/
retry.url = (args: { repository: string | { slug: string }, task: string | { public_id: string } } | [repository: string | { slug: string }, task: string | { public_id: string } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            repository: args[0],
            task: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repository: typeof args.repository === 'object'
        ? args.repository.slug
        : args.repository,
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return retry.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::retry
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:76
* @route '/repos/{repository}/runs/{task}/retry'
*/
retry.post = (args: { repository: string | { slug: string }, task: string | { public_id: string } } | [repository: string | { slug: string }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

const RepositoryRunController = { show, log, retry }

export default RepositoryRunController