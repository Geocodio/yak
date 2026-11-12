import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::store
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:50
* @route '/settings/claude-accounts'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/settings/claude-accounts',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::store
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:50
* @route '/settings/claude-accounts'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::store
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:50
* @route '/settings/claude-accounts'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::rotate
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:93
* @route '/settings/claude-accounts/{account}/rotate'
*/
export const rotate = (args: { account: string | number | { id: string | number } } | [account: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotate.url(args, options),
    method: 'post',
})

rotate.definition = {
    methods: ["post"],
    url: '/settings/claude-accounts/{account}/rotate',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::rotate
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:93
* @route '/settings/claude-accounts/{account}/rotate'
*/
rotate.url = (args: { account: string | number | { id: string | number } } | [account: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { account: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { account: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            account: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        account: typeof args.account === 'object'
        ? args.account.id
        : args.account,
    }

    return rotate.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::rotate
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:93
* @route '/settings/claude-accounts/{account}/rotate'
*/
rotate.post = (args: { account: string | number | { id: string | number } } | [account: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotate.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::update
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:112
* @route '/settings/claude-accounts/{account}'
*/
export const update = (args: { account: string | number | { id: string | number } } | [account: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/claude-accounts/{account}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::update
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:112
* @route '/settings/claude-accounts/{account}'
*/
update.url = (args: { account: string | number | { id: string | number } } | [account: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { account: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { account: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            account: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        account: typeof args.account === 'object'
        ? args.account.id
        : args.account,
    }

    return update.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::update
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:112
* @route '/settings/claude-accounts/{account}'
*/
update.patch = (args: { account: string | number | { id: string | number } } | [account: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::destroy
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:125
* @route '/settings/claude-accounts/{account}'
*/
export const destroy = (args: { account: string | number | { id: string | number } } | [account: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/settings/claude-accounts/{account}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::destroy
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:125
* @route '/settings/claude-accounts/{account}'
*/
destroy.url = (args: { account: string | number | { id: string | number } } | [account: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { account: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { account: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            account: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        account: typeof args.account === 'object'
        ? args.account.id
        : args.account,
    }

    return destroy.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::destroy
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:125
* @route '/settings/claude-accounts/{account}'
*/
destroy.delete = (args: { account: string | number | { id: string | number } } | [account: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const claudeAccounts = {
    store: Object.assign(store, store),
    rotate: Object.assign(rotate, rotate),
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
}

export default claudeAccounts