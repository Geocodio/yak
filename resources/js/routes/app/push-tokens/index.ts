import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Push\PushTokenController::update
* @see app/Http/Controllers/Api/App/Push/PushTokenController.php:24
* @route '/api/app/v1/push-tokens/{kind}'
*/
export const update = (args: { kind: string | number } | [kind: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/api/app/v1/push-tokens/{kind}',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Api\App\Push\PushTokenController::update
* @see app/Http/Controllers/Api/App/Push/PushTokenController.php:24
* @route '/api/app/v1/push-tokens/{kind}'
*/
update.url = (args: { kind: string | number } | [kind: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { kind: args }
    }

    if (Array.isArray(args)) {
        args = {
            kind: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        kind: args.kind,
    }

    return update.definition.url
            .replace('{kind}', parsedArgs.kind.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Push\PushTokenController::update
* @see app/Http/Controllers/Api/App/Push/PushTokenController.php:24
* @route '/api/app/v1/push-tokens/{kind}'
*/
update.put = (args: { kind: string | number } | [kind: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Api\App\Push\PushTokenController::destroy
* @see app/Http/Controllers/Api/App/Push/PushTokenController.php:56
* @route '/api/app/v1/push-tokens/{kind}'
*/
export const destroy = (args: { kind: string | number } | [kind: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/api/app/v1/push-tokens/{kind}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Api\App\Push\PushTokenController::destroy
* @see app/Http/Controllers/Api/App/Push/PushTokenController.php:56
* @route '/api/app/v1/push-tokens/{kind}'
*/
destroy.url = (args: { kind: string | number } | [kind: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { kind: args }
    }

    if (Array.isArray(args)) {
        args = {
            kind: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        kind: args.kind,
    }

    return destroy.definition.url
            .replace('{kind}', parsedArgs.kind.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Push\PushTokenController::destroy
* @see app/Http/Controllers/Api/App/Push/PushTokenController.php:56
* @route '/api/app/v1/push-tokens/{kind}'
*/
destroy.delete = (args: { kind: string | number } | [kind: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Api\App\Push\PushTestController::__invoke
* @see app/Http/Controllers/Api/App/Push/PushTestController.php:24
* @route '/api/app/v1/push-tokens/alert/test'
*/
export const test = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: test.url(options),
    method: 'post',
})

test.definition = {
    methods: ["post"],
    url: '/api/app/v1/push-tokens/alert/test',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Push\PushTestController::__invoke
* @see app/Http/Controllers/Api/App/Push/PushTestController.php:24
* @route '/api/app/v1/push-tokens/alert/test'
*/
test.url = (options?: RouteQueryOptions) => {
    return test.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Push\PushTestController::__invoke
* @see app/Http/Controllers/Api/App/Push/PushTestController.php:24
* @route '/api/app/v1/push-tokens/alert/test'
*/
test.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: test.url(options),
    method: 'post',
})

const pushTokens = {
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
    test: Object.assign(test, test),
}

export default pushTokens