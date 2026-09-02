import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\AccountController::destroy
* @see app/Http/Controllers/Settings/AccountController.php:13
* @route '/settings/account'
*/
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/settings/account',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\AccountController::destroy
* @see app/Http/Controllers/Settings/AccountController.php:13
* @route '/settings/account'
*/
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AccountController::destroy
* @see app/Http/Controllers/Settings/AccountController.php:13
* @route '/settings/account'
*/
destroy.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

const AccountController = { destroy }

export default AccountController