import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\DeviceController::destroy
* @see app/Http/Controllers/Settings/DeviceController.php:37
* @route '/settings/devices/{device}'
*/
export const destroy = (args: { device: number | { id: number } } | [device: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/settings/devices/{device}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\DeviceController::destroy
* @see app/Http/Controllers/Settings/DeviceController.php:37
* @route '/settings/devices/{device}'
*/
destroy.url = (args: { device: number | { id: number } } | [device: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { device: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { device: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            device: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        device: typeof args.device === 'object'
        ? args.device.id
        : args.device,
    }

    return destroy.definition.url
            .replace('{device}', parsedArgs.device.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\DeviceController::destroy
* @see app/Http/Controllers/Settings/DeviceController.php:37
* @route '/settings/devices/{device}'
*/
destroy.delete = (args: { device: number | { id: number } } | [device: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const devices = {
    destroy: Object.assign(destroy, destroy),
}

export default devices