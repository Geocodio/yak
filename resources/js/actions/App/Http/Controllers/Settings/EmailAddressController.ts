import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
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

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::confirm
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:85
* @route '/email-addresses/{emailAddress}/confirm'
*/
export const confirm = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: confirm.url(args, options),
    method: 'post',
})

confirm.definition = {
    methods: ["post"],
    url: '/email-addresses/{emailAddress}/confirm',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::confirm
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:85
* @route '/email-addresses/{emailAddress}/confirm'
*/
confirm.url = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return confirm.definition.url
            .replace('{emailAddress}', parsedArgs.emailAddress.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\EmailAddressController::confirm
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/EmailAddressController.php:85
* @route '/email-addresses/{emailAddress}/confirm'
*/
confirm.post = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: confirm.url(args, options),
    method: 'post',
})

const EmailAddressController = { store, resend, destroy, show, confirm }

export default EmailAddressController