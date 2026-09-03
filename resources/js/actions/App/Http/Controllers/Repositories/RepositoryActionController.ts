import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::toggleActive
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:14
* @route '/repos/{repository}/toggle-active'
*/
export const toggleActive = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: toggleActive.url(args, options),
    method: 'post',
})

toggleActive.definition = {
    methods: ["post"],
    url: '/repos/{repository}/toggle-active',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::toggleActive
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:14
* @route '/repos/{repository}/toggle-active'
*/
toggleActive.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
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

    return toggleActive.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::toggleActive
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:14
* @route '/repos/{repository}/toggle-active'
*/
toggleActive.post = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: toggleActive.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::rerunSetup
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:22
* @route '/repos/{repository}/rerun-setup'
*/
export const rerunSetup = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerunSetup.url(args, options),
    method: 'post',
})

rerunSetup.definition = {
    methods: ["post"],
    url: '/repos/{repository}/rerun-setup',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::rerunSetup
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:22
* @route '/repos/{repository}/rerun-setup'
*/
rerunSetup.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
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

    return rerunSetup.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::rerunSetup
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:22
* @route '/repos/{repository}/rerun-setup'
*/
rerunSetup.post = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerunSetup.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::reviewOpenPrs
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:29
* @route '/repos/{repository}/review-open-prs'
*/
export const reviewOpenPrs = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reviewOpenPrs.url(args, options),
    method: 'post',
})

reviewOpenPrs.definition = {
    methods: ["post"],
    url: '/repos/{repository}/review-open-prs',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::reviewOpenPrs
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:29
* @route '/repos/{repository}/review-open-prs'
*/
reviewOpenPrs.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
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

    return reviewOpenPrs.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::reviewOpenPrs
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:29
* @route '/repos/{repository}/review-open-prs'
*/
reviewOpenPrs.post = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reviewOpenPrs.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::rebuildDeployments
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:36
* @route '/repos/{repository}/rebuild-deployments'
*/
export const rebuildDeployments = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rebuildDeployments.url(args, options),
    method: 'post',
})

rebuildDeployments.definition = {
    methods: ["post"],
    url: '/repos/{repository}/rebuild-deployments',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::rebuildDeployments
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:36
* @route '/repos/{repository}/rebuild-deployments'
*/
rebuildDeployments.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
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

    return rebuildDeployments.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryActionController::rebuildDeployments
* @see app/Http/Controllers/Repositories/RepositoryActionController.php:36
* @route '/repos/{repository}/rebuild-deployments'
*/
rebuildDeployments.post = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rebuildDeployments.url(args, options),
    method: 'post',
})

const RepositoryActionController = { toggleActive, rerunSetup, reviewOpenPrs, rebuildDeployments }

export default RepositoryActionController