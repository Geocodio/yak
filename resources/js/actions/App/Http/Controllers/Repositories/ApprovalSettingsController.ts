import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::show
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:22
* @route '/repos/{repository}/approvals'
*/
export const show = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/repos/{repository}/approvals',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::show
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:22
* @route '/repos/{repository}/approvals'
*/
show.url = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions) => {
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
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:22
* @route '/repos/{repository}/approvals'
*/
show.get = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::show
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:22
* @route '/repos/{repository}/approvals'
*/
show.head = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::update
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:39
* @route '/repos/{repository}/approvals'
*/
export const update = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/repos/{repository}/approvals',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::update
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:39
* @route '/repos/{repository}/approvals'
*/
update.url = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions) => {
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
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:39
* @route '/repos/{repository}/approvals'
*/
update.put = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::check
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:59
* @route '/repos/{repository}/approvals/check'
*/
export const check = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: check.url(args, options),
    method: 'post',
})

check.definition = {
    methods: ["post"],
    url: '/repos/{repository}/approvals/check',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::check
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:59
* @route '/repos/{repository}/approvals/check'
*/
check.url = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions) => {
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

    return check.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::check
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:59
* @route '/repos/{repository}/approvals/check'
*/
check.post = (args: { repository: string | { slug: string } } | [repository: string | { slug: string } ] | string | { slug: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: check.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::review
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:70
* @route '/repos/{repository}/approvals/review/{number}'
*/
export const review = (args: { repository: string | { slug: string }, number: string | number } | [repository: string | { slug: string }, number: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: review.url(args, options),
    method: 'post',
})

review.definition = {
    methods: ["post"],
    url: '/repos/{repository}/approvals/review/{number}',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::review
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:70
* @route '/repos/{repository}/approvals/review/{number}'
*/
review.url = (args: { repository: string | { slug: string }, number: string | number } | [repository: string | { slug: string }, number: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            repository: args[0],
            number: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repository: typeof args.repository === 'object'
        ? args.repository.slug
        : args.repository,
        number: args.number,
    }

    return review.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace('{number}', parsedArgs.number.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\ApprovalSettingsController::review
* @see app/Http/Controllers/Repositories/ApprovalSettingsController.php:70
* @route '/repos/{repository}/approvals/review/{number}'
*/
review.post = (args: { repository: string | { slug: string }, number: string | number } | [repository: string | { slug: string }, number: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: review.url(args, options),
    method: 'post',
})

const ApprovalSettingsController = { show, update, check, review }

export default ApprovalSettingsController