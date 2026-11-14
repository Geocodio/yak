import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\DevicePairingCodeController::store
* @see app/Http/Controllers/Settings/DevicePairingCodeController.php:18
* @route '/settings/devices/pairing-codes'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/settings/devices/pairing-codes',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\DevicePairingCodeController::store
* @see app/Http/Controllers/Settings/DevicePairingCodeController.php:18
* @route '/settings/devices/pairing-codes'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\DevicePairingCodeController::store
* @see app/Http/Controllers/Settings/DevicePairingCodeController.php:18
* @route '/settings/devices/pairing-codes'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\DevicePairingCodeController::show
* @see app/Http/Controllers/Settings/DevicePairingCodeController.php:35
* @route '/settings/devices/pairing-codes/{devicePairingCode}'
*/
export const show = (args: { devicePairingCode: string | { id: string } } | [devicePairingCode: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/settings/devices/pairing-codes/{devicePairingCode}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\DevicePairingCodeController::show
* @see app/Http/Controllers/Settings/DevicePairingCodeController.php:35
* @route '/settings/devices/pairing-codes/{devicePairingCode}'
*/
show.url = (args: { devicePairingCode: string | { id: string } } | [devicePairingCode: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { devicePairingCode: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { devicePairingCode: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            devicePairingCode: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        devicePairingCode: typeof args.devicePairingCode === 'object'
        ? args.devicePairingCode.id
        : args.devicePairingCode,
    }

    return show.definition.url
            .replace('{devicePairingCode}', parsedArgs.devicePairingCode.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\DevicePairingCodeController::show
* @see app/Http/Controllers/Settings/DevicePairingCodeController.php:35
* @route '/settings/devices/pairing-codes/{devicePairingCode}'
*/
show.get = (args: { devicePairingCode: string | { id: string } } | [devicePairingCode: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\DevicePairingCodeController::show
* @see app/Http/Controllers/Settings/DevicePairingCodeController.php:35
* @route '/settings/devices/pairing-codes/{devicePairingCode}'
*/
show.head = (args: { devicePairingCode: string | { id: string } } | [devicePairingCode: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const pairingCodes = {
    store: Object.assign(store, store),
    show: Object.assign(show, show),
}

export default pairingCodes