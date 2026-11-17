import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::index
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs'
*/
export const index = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/repos/{repository}/runs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::index
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs'
*/
index.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
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

    return index.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::index
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs'
*/
index.get = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::index
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs'
*/
index.head = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::show
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs/{task}'
*/
export const show = (args: { repository: string | number | { slug: string | number }, task: string | { public_id: string } } | [repository: string | number | { slug: string | number }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/repos/{repository}/runs/{task}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::show
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs/{task}'
*/
show.url = (args: { repository: string | number | { slug: string | number }, task: string | { public_id: string } } | [repository: string | number | { slug: string | number }, task: string | { public_id: string } ], options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::show
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs/{task}'
*/
show.get = (args: { repository: string | number | { slug: string | number }, task: string | { public_id: string } } | [repository: string | number | { slug: string | number }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::show
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:27
* @route '/repos/{repository}/runs/{task}'
*/
show.head = (args: { repository: string | number | { slug: string | number }, task: string | { public_id: string } } | [repository: string | number | { slug: string | number }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::log
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:61
* @route '/repos/{repository}/runs/{task}/log'
*/
export const log = (args: { repository: string | number | { slug: string | number }, task: string | { public_id: string } } | [repository: string | number | { slug: string | number }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(args, options),
    method: 'get',
})

log.definition = {
    methods: ["get","head"],
    url: '/repos/{repository}/runs/{task}/log',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::log
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:61
* @route '/repos/{repository}/runs/{task}/log'
*/
log.url = (args: { repository: string | number | { slug: string | number }, task: string | { public_id: string } } | [repository: string | number | { slug: string | number }, task: string | { public_id: string } ], options?: RouteQueryOptions) => {
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
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:61
* @route '/repos/{repository}/runs/{task}/log'
*/
log.get = (args: { repository: string | number | { slug: string | number }, task: string | { public_id: string } } | [repository: string | number | { slug: string | number }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::log
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:61
* @route '/repos/{repository}/runs/{task}/log'
*/
log.head = (args: { repository: string | number | { slug: string | number }, task: string | { public_id: string } } | [repository: string | number | { slug: string | number }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: log.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::retry
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:74
* @route '/repos/{repository}/runs/{task}/retry'
*/
export const retry = (args: { repository: string | number | { slug: string | number }, task: string | { public_id: string } } | [repository: string | number | { slug: string | number }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

retry.definition = {
    methods: ["post"],
    url: '/repos/{repository}/runs/{task}/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryRunController::retry
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:74
* @route '/repos/{repository}/runs/{task}/retry'
*/
retry.url = (args: { repository: string | number | { slug: string | number }, task: string | { public_id: string } } | [repository: string | number | { slug: string | number }, task: string | { public_id: string } ], options?: RouteQueryOptions) => {
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
* @see app/Http/Controllers/Repositories/RepositoryRunController.php:74
* @route '/repos/{repository}/runs/{task}/retry'
*/
retry.post = (args: { repository: string | number | { slug: string | number }, task: string | { public_id: string } } | [repository: string | number | { slug: string | number }, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

const runs = {
    index: Object.assign(index, index),
    show: Object.assign(show, show),
    log: Object.assign(log, log),
    retry: Object.assign(retry, retry),
}

export default runs