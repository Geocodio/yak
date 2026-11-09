import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::refresh
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:61
* @route '/repos/{repository}/config/refresh'
*/
export const refresh = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(args, options),
    method: 'post',
})

refresh.definition = {
    methods: ["post"],
    url: '/repos/{repository}/config/refresh',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::refresh
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:61
* @route '/repos/{repository}/config/refresh'
*/
refresh.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
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

    return refresh.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::refresh
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:61
* @route '/repos/{repository}/config/refresh'
*/
refresh.post = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::migrate
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:68
* @route '/repos/{repository}/config/migrate'
*/
export const migrate = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: migrate.url(args, options),
    method: 'post',
})

migrate.definition = {
    methods: ["post"],
    url: '/repos/{repository}/config/migrate',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::migrate
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:68
* @route '/repos/{repository}/config/migrate'
*/
migrate.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
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

    return migrate.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::migrate
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:68
* @route '/repos/{repository}/config/migrate'
*/
migrate.post = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: migrate.url(args, options),
    method: 'post',
})

const config = {
    refresh: Object.assign(refresh, refresh),
    migrate: Object.assign(migrate, migrate),
}

export default config