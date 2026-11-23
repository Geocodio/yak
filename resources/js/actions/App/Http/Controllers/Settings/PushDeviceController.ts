import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\PushDeviceController::store
* @see app/Http/Controllers/Settings/PushDeviceController.php:16
* @route '/settings/notifications/devices'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/settings/notifications/devices',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\PushDeviceController::store
* @see app/Http/Controllers/Settings/PushDeviceController.php:16
* @route '/settings/notifications/devices'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\PushDeviceController::store
* @see app/Http/Controllers/Settings/PushDeviceController.php:16
* @route '/settings/notifications/devices'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\PushDeviceController::update
* @see app/Http/Controllers/Settings/PushDeviceController.php:39
* @route '/settings/notifications/devices/{pushDevice}'
*/
export const update = (args: { pushDevice: string | number | { id: string | number } } | [pushDevice: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/notifications/devices/{pushDevice}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\PushDeviceController::update
* @see app/Http/Controllers/Settings/PushDeviceController.php:39
* @route '/settings/notifications/devices/{pushDevice}'
*/
update.url = (args: { pushDevice: string | number | { id: string | number } } | [pushDevice: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { pushDevice: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { pushDevice: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            pushDevice: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        pushDevice: typeof args.pushDevice === 'object'
        ? args.pushDevice.id
        : args.pushDevice,
    }

    return update.definition.url
            .replace('{pushDevice}', parsedArgs.pushDevice.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\PushDeviceController::update
* @see app/Http/Controllers/Settings/PushDeviceController.php:39
* @route '/settings/notifications/devices/{pushDevice}'
*/
update.patch = (args: { pushDevice: string | number | { id: string | number } } | [pushDevice: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\PushDeviceController::destroy
* @see app/Http/Controllers/Settings/PushDeviceController.php:48
* @route '/settings/notifications/devices/{pushDevice}'
*/
export const destroy = (args: { pushDevice: string | number | { id: string | number } } | [pushDevice: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/settings/notifications/devices/{pushDevice}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\PushDeviceController::destroy
* @see app/Http/Controllers/Settings/PushDeviceController.php:48
* @route '/settings/notifications/devices/{pushDevice}'
*/
destroy.url = (args: { pushDevice: string | number | { id: string | number } } | [pushDevice: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { pushDevice: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { pushDevice: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            pushDevice: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        pushDevice: typeof args.pushDevice === 'object'
        ? args.pushDevice.id
        : args.pushDevice,
    }

    return destroy.definition.url
            .replace('{pushDevice}', parsedArgs.pushDevice.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\PushDeviceController::destroy
* @see app/Http/Controllers/Settings/PushDeviceController.php:48
* @route '/settings/notifications/devices/{pushDevice}'
*/
destroy.delete = (args: { pushDevice: string | number | { id: string | number } } | [pushDevice: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Settings\PushDeviceController::test
* @see app/Http/Controllers/Settings/PushDeviceController.php:57
* @route '/settings/notifications/devices/{pushDevice}/test'
*/
export const test = (args: { pushDevice: string | number | { id: string | number } } | [pushDevice: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: test.url(args, options),
    method: 'post',
})

test.definition = {
    methods: ["post"],
    url: '/settings/notifications/devices/{pushDevice}/test',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\PushDeviceController::test
* @see app/Http/Controllers/Settings/PushDeviceController.php:57
* @route '/settings/notifications/devices/{pushDevice}/test'
*/
test.url = (args: { pushDevice: string | number | { id: string | number } } | [pushDevice: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { pushDevice: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { pushDevice: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            pushDevice: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        pushDevice: typeof args.pushDevice === 'object'
        ? args.pushDevice.id
        : args.pushDevice,
    }

    return test.definition.url
            .replace('{pushDevice}', parsedArgs.pushDevice.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\PushDeviceController::test
* @see app/Http/Controllers/Settings/PushDeviceController.php:57
* @route '/settings/notifications/devices/{pushDevice}/test'
*/
test.post = (args: { pushDevice: string | number | { id: string | number } } | [pushDevice: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: test.url(args, options),
    method: 'post',
})

const PushDeviceController = { store, update, destroy, test }

export default PushDeviceController