import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Repositories\RepositorySettingsController::show
* @see app/Http/Controllers/Repositories/RepositorySettingsController.php:14
* @route '/repos/{repository}/settings/{section}'
*/
export const show = (args: { repository: string | { slug: string }, section: string | number } | [repository: string | { slug: string }, section: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/repos/{repository}/settings/{section}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\RepositorySettingsController::show
* @see app/Http/Controllers/Repositories/RepositorySettingsController.php:14
* @route '/repos/{repository}/settings/{section}'
*/
show.url = (args: { repository: string | { slug: string }, section: string | number } | [repository: string | { slug: string }, section: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            repository: args[0],
            section: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repository: typeof args.repository === 'object'
        ? args.repository.slug
        : args.repository,
        section: args.section,
    }

    return show.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace('{section}', parsedArgs.section.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositorySettingsController::show
* @see app/Http/Controllers/Repositories/RepositorySettingsController.php:14
* @route '/repos/{repository}/settings/{section}'
*/
show.get = (args: { repository: string | { slug: string }, section: string | number } | [repository: string | { slug: string }, section: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\RepositorySettingsController::show
* @see app/Http/Controllers/Repositories/RepositorySettingsController.php:14
* @route '/repos/{repository}/settings/{section}'
*/
show.head = (args: { repository: string | { slug: string }, section: string | number } | [repository: string | { slug: string }, section: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const settings = {
    show: Object.assign(show, show),
}

export default settings