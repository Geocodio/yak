import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\EmailAddressController::store
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:26
* @route '/settings/profile/email-addresses'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/settings/profile/email-addresses',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::store
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:26
* @route '/settings/profile/email-addresses'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::store
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:26
* @route '/settings/profile/email-addresses'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::resend
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:48
* @route '/settings/profile/email-addresses/{emailAddress}/resend'
*/
export const resend = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: resend.url(args, options),
    method: 'post',
})

resend.definition = {
    methods: ["post"],
    url: '/settings/profile/email-addresses/{emailAddress}/resend',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::resend
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:48
* @route '/settings/profile/email-addresses/{emailAddress}/resend'
*/
resend.url = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return resend.definition.url
            .replace('{emailAddress}', parsedArgs.emailAddress.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::resend
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:48
* @route '/settings/profile/email-addresses/{emailAddress}/resend'
*/
resend.post = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: resend.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::destroy
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:63
* @route '/settings/profile/email-addresses/{emailAddress}'
*/
export const destroy = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/settings/profile/email-addresses/{emailAddress}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::destroy
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:63
* @route '/settings/profile/email-addresses/{emailAddress}'
*/
destroy.url = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{emailAddress}', parsedArgs.emailAddress.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::destroy
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:63
* @route '/settings/profile/email-addresses/{emailAddress}'
*/
destroy.delete = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const emailAddresses = {
    store: Object.assign(store, store),
    resend: Object.assign(resend, resend),
    destroy: Object.assign(destroy, destroy),
}

export default emailAddresses