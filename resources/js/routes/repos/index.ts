import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
import manifest from './manifest'
/**
* @see \App\Http\Controllers\Repositories\RepositoryController::create
* @see app/Http/Controllers/Repositories/RepositoryController.php:30
* @route '/repos/create'
*/
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

create.definition = {
    methods: ["get","head"],
    url: '/repos/create',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::create
* @see app/Http/Controllers/Repositories/RepositoryController.php:30
* @route '/repos/create'
*/
create.url = (options?: RouteQueryOptions) => {
    return create.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::create
* @see app/Http/Controllers/Repositories/RepositoryController.php:30
* @route '/repos/create'
*/
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::create
* @see app/Http/Controllers/Repositories/RepositoryController.php:30
* @route '/repos/create'
*/
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::store
* @see app/Http/Controllers/Repositories/RepositoryController.php:45
* @route '/repos'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/repos',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::store
* @see app/Http/Controllers/Repositories/RepositoryController.php:45
* @route '/repos'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::store
* @see app/Http/Controllers/Repositories/RepositoryController.php:45
* @route '/repos'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Repositories\GitHubRepoSearchController::__invoke
* @see app/Http/Controllers/Repositories/GitHubRepoSearchController.php:15
* @route '/repos/github-search'
*/
export const githubSearch = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: githubSearch.url(options),
    method: 'get',
})

githubSearch.definition = {
    methods: ["get","head"],
    url: '/repos/github-search',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\GitHubRepoSearchController::__invoke
* @see app/Http/Controllers/Repositories/GitHubRepoSearchController.php:15
* @route '/repos/github-search'
*/
githubSearch.url = (options?: RouteQueryOptions) => {
    return githubSearch.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\GitHubRepoSearchController::__invoke
* @see app/Http/Controllers/Repositories/GitHubRepoSearchController.php:15
* @route '/repos/github-search'
*/
githubSearch.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: githubSearch.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\GitHubRepoSearchController::__invoke
* @see app/Http/Controllers/Repositories/GitHubRepoSearchController.php:15
* @route '/repos/github-search'
*/
githubSearch.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: githubSearch.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Repositories\GitHubCiDetectController::__invoke
* @see app/Http/Controllers/Repositories/GitHubCiDetectController.php:18
* @route '/repos/github-detect'
*/
export const githubDetect = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: githubDetect.url(options),
    method: 'get',
})

githubDetect.definition = {
    methods: ["get","head"],
    url: '/repos/github-detect',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\GitHubCiDetectController::__invoke
* @see app/Http/Controllers/Repositories/GitHubCiDetectController.php:18
* @route '/repos/github-detect'
*/
githubDetect.url = (options?: RouteQueryOptions) => {
    return githubDetect.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\GitHubCiDetectController::__invoke
* @see app/Http/Controllers/Repositories/GitHubCiDetectController.php:18
* @route '/repos/github-detect'
*/
githubDetect.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: githubDetect.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\GitHubCiDetectController::__invoke
* @see app/Http/Controllers/Repositories/GitHubCiDetectController.php:18
* @route '/repos/github-detect'
*/
githubDetect.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: githubDetect.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::edit
* @see app/Http/Controllers/Repositories/RepositoryController.php:76
* @route '/repos/{repository}/edit'
*/
export const edit = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(args, options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/repos/{repository}/edit',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::edit
* @see app/Http/Controllers/Repositories/RepositoryController.php:76
* @route '/repos/{repository}/edit'
*/
edit.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
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

    return edit.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::edit
* @see app/Http/Controllers/Repositories/RepositoryController.php:76
* @route '/repos/{repository}/edit'
*/
edit.get = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::edit
* @see app/Http/Controllers/Repositories/RepositoryController.php:76
* @route '/repos/{repository}/edit'
*/
edit.head = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::update
* @see app/Http/Controllers/Repositories/RepositoryController.php:110
* @route '/repos/{repository}'
*/
export const update = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/repos/{repository}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::update
* @see app/Http/Controllers/Repositories/RepositoryController.php:110
* @route '/repos/{repository}'
*/
update.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::update
* @see app/Http/Controllers/Repositories/RepositoryController.php:110
* @route '/repos/{repository}'
*/
update.patch = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::destroy
* @see app/Http/Controllers/Repositories/RepositoryController.php:141
* @route '/repos/{repository}'
*/
export const destroy = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/repos/{repository}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::destroy
* @see app/Http/Controllers/Repositories/RepositoryController.php:141
* @route '/repos/{repository}'
*/
destroy.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::destroy
* @see app/Http/Controllers/Repositories/RepositoryController.php:141
* @route '/repos/{repository}'
*/
destroy.delete = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

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

const repos = {
    create: Object.assign(create, create),
    store: Object.assign(store, store),
    githubSearch: Object.assign(githubSearch, githubSearch),
    githubDetect: Object.assign(githubDetect, githubDetect),
    edit: Object.assign(edit, edit),
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
    toggleActive: Object.assign(toggleActive, toggleActive),
    rerunSetup: Object.assign(rerunSetup, rerunSetup),
    reviewOpenPrs: Object.assign(reviewOpenPrs, reviewOpenPrs),
    rebuildDeployments: Object.assign(rebuildDeployments, rebuildDeployments),
    manifest: Object.assign(manifest, manifest),
}

export default repos