import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Repositories\RepositoryClaudeAccountController::update
* @see app/Http/Controllers/Repositories/RepositoryClaudeAccountController.php:30
* @route '/repos/{repository}/unattended-claude-account'
*/
export const update = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/repos/{repository}/unattended-claude-account',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryClaudeAccountController::update
* @see app/Http/Controllers/Repositories/RepositoryClaudeAccountController.php:30
* @route '/repos/{repository}/unattended-claude-account'
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
* @see \App\Http\Controllers\Repositories\RepositoryClaudeAccountController::update
* @see app/Http/Controllers/Repositories/RepositoryClaudeAccountController.php:30
* @route '/repos/{repository}/unattended-claude-account'
*/
update.put = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

const unattendedClaudeAccount = {
    update: Object.assign(update, update),
}

export default unattendedClaudeAccount