import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\EmailAddressController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:72
* @route '/email-addresses/{emailAddress}/confirm'
*/
export const show = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/email-addresses/{emailAddress}/confirm',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:72
* @route '/email-addresses/{emailAddress}/confirm'
*/
show.url = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { emailAddress: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { emailAddress: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            emailAddress: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        emailAddress: typeof args.emailAddress === 'object'
        ? args.emailAddress.id
        : args.emailAddress,
    }

    return show.definition.url
            .replace('{emailAddress}', parsedArgs.emailAddress.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:72
* @route '/email-addresses/{emailAddress}/confirm'
*/
show.get = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:72
* @route '/email-addresses/{emailAddress}/confirm'
*/
show.head = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

