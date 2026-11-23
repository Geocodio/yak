import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\LimitsController::index
* @see app/Http/Controllers/Settings/LimitsController.php:18
* @route '/settings/limits'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/settings/limits',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\LimitsController::index
* @see app/Http/Controllers/Settings/LimitsController.php:18
* @route '/settings/limits'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\LimitsController::index
* @see app/Http/Controllers/Settings/LimitsController.php:18
* @route '/settings/limits'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\LimitsController::index
* @see app/Http/Controllers/Settings/LimitsController.php:18
* @route '/settings/limits'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\LimitsController::update
* @see app/Http/Controllers/Settings/LimitsController.php:28
* @route '/settings/limits'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/settings/limits',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Settings\LimitsController::update
* @see app/Http/Controllers/Settings/LimitsController.php:28
* @route '/settings/limits'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\LimitsController::update
* @see app/Http/Controllers/Settings/LimitsController.php:28
* @route '/settings/limits'
*/
update.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Settings\LimitsController::reset
* @see app/Http/Controllers/Settings/LimitsController.php:43
* @route '/settings/limits/reset'
*/
export const reset = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reset.url(options),
    method: 'post',
})

reset.definition = {
    methods: ["post"],
    url: '/settings/limits/reset',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\LimitsController::reset
* @see app/Http/Controllers/Settings/LimitsController.php:43
* @route '/settings/limits/reset'
*/
reset.url = (options?: RouteQueryOptions) => {
    return reset.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\LimitsController::reset
* @see app/Http/Controllers/Settings/LimitsController.php:43
* @route '/settings/limits/reset'
*/
reset.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reset.url(options),
    method: 'post',
})

const LimitsController = { index, update, reset }

export default LimitsController