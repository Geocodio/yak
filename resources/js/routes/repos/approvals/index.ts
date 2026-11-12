import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::show
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:18
* @route '/repos/{repository}/approvals'
*/
export const show = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/repos/{repository}/approvals',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::show
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:18
* @route '/repos/{repository}/approvals'
*/
show.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::show
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:18
* @route '/repos/{repository}/approvals'
*/
show.get = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::show
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:18
* @route '/repos/{repository}/approvals'
*/
show.head = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::update
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:31
* @route '/repos/{repository}/approvals'
*/
export const update = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/repos/{repository}/approvals',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::update
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:31
* @route '/repos/{repository}/approvals'
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
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::update
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:31
* @route '/repos/{repository}/approvals'
*/
update.put = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

const approvals = {
    show: Object.assign(show, show),
    update: Object.assign(update, update),
}

export default approvals