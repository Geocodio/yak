import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Repositories\RepositoryClaudeAccountController::updateMine
* @see app/Http/Controllers/Repositories/RepositoryClaudeAccountController.php:13
* @route '/repos/{repository}/claude-account'
*/
export const updateMine = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateMine.url(args, options),
    method: 'put',
})

updateMine.definition = {
    methods: ["put"],
    url: '/repos/{repository}/claude-account',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryClaudeAccountController::updateMine
* @see app/Http/Controllers/Repositories/RepositoryClaudeAccountController.php:13
* @route '/repos/{repository}/claude-account'
*/
updateMine.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
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

    return updateMine.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryClaudeAccountController::updateMine
* @see app/Http/Controllers/Repositories/RepositoryClaudeAccountController.php:13
* @route '/repos/{repository}/claude-account'
*/
updateMine.put = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateMine.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryClaudeAccountController::updateUnattended
* @see app/Http/Controllers/Repositories/RepositoryClaudeAccountController.php:30
* @route '/repos/{repository}/unattended-claude-account'
*/
export const updateUnattended = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateUnattended.url(args, options),
    method: 'put',
})

updateUnattended.definition = {
    methods: ["put"],
    url: '/repos/{repository}/unattended-claude-account',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryClaudeAccountController::updateUnattended
* @see app/Http/Controllers/Repositories/RepositoryClaudeAccountController.php:30
* @route '/repos/{repository}/unattended-claude-account'
*/
updateUnattended.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
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

    return updateUnattended.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryClaudeAccountController::updateUnattended
* @see app/Http/Controllers/Repositories/RepositoryClaudeAccountController.php:30
* @route '/repos/{repository}/unattended-claude-account'
*/
updateUnattended.put = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateUnattended.url(args, options),
    method: 'put',
})

const RepositoryClaudeAccountController = { updateMine, updateUnattended }

export default RepositoryClaudeAccountController