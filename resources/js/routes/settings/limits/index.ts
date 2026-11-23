import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\LimitsController::update
* @see [unknown]:0
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
* @see [unknown]:0
* @route '/settings/limits'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\LimitsController::update
* @see [unknown]:0
* @route '/settings/limits'
*/
update.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Settings\LimitsController::reset
* @see [unknown]:0
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
* @see [unknown]:0
* @route '/settings/limits/reset'
*/
reset.url = (options?: RouteQueryOptions) => {
    return reset.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\LimitsController::reset
* @see [unknown]:0
* @route '/settings/limits/reset'
*/
reset.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reset.url(options),
    method: 'post',
})

const limits = {
    update: Object.assign(update, update),
    reset: Object.assign(reset, reset),
}

export default limits