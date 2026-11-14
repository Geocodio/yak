import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\SshKeyController::store
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/SshKeyController.php:27
* @route '/settings/ssh-keys'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/settings/ssh-keys',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\SshKeyController::store
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/SshKeyController.php:27
* @route '/settings/ssh-keys'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\SshKeyController::store
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/SshKeyController.php:27
* @route '/settings/ssh-keys'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\SshKeyController::refresh
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/SshKeyController.php:47
* @route '/settings/ssh-keys/refresh'
*/
export const refresh = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(options),
    method: 'post',
})

refresh.definition = {
    methods: ["post"],
    url: '/settings/ssh-keys/refresh',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\SshKeyController::refresh
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/SshKeyController.php:47
* @route '/settings/ssh-keys/refresh'
*/
refresh.url = (options?: RouteQueryOptions) => {
    return refresh.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\SshKeyController::refresh
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/SshKeyController.php:47
* @route '/settings/ssh-keys/refresh'
*/
refresh.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\SshKeyController::destroy
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/SshKeyController.php:38
* @route '/settings/ssh-keys/{key}'
*/
export const destroy = (args: { key: number | { id: number } } | [key: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/settings/ssh-keys/{key}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\SshKeyController::destroy
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/SshKeyController.php:38
* @route '/settings/ssh-keys/{key}'
*/
destroy.url = (args: { key: number | { id: number } } | [key: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { key: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { key: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            key: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        key: typeof args.key === 'object'
        ? args.key.id
        : args.key,
    }

    return destroy.definition.url
            .replace('{key}', parsedArgs.key.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\SshKeyController::destroy
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/SshKeyController.php:38
* @route '/settings/ssh-keys/{key}'
*/
destroy.delete = (args: { key: number | { id: number } } | [key: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const sshKeys = {
    store: Object.assign(store, store),
    refresh: Object.assign(refresh, refresh),
    destroy: Object.assign(destroy, destroy),
}

export default sshKeys