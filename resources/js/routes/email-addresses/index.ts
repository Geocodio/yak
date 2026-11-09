import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\EmailAddressController::confirm
* @see app/Http/Controllers/Settings/EmailAddressController.php:73
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
* @see app/Http/Controllers/Settings/EmailAddressController.php:73
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
* @see app/Http/Controllers/Settings/EmailAddressController.php:73
* @route '/email-addresses/{emailAddress}/confirm'
*/
confirm.post = (args: { emailAddress: number | { id: number } } | [emailAddress: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: confirm.url(args, options),
    method: 'post',
})

const emailAddresses = {
    confirm: Object.assign(confirm, confirm),
}

export default emailAddresses