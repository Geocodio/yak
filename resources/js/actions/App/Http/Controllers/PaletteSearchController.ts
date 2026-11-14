import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\PaletteSearchController::__invoke
* @see app/Http/Controllers/PaletteSearchController.php:12
* @route '/palette/search'
*/
const PaletteSearchController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PaletteSearchController.url(options),
    method: 'get',
})

PaletteSearchController.definition = {
    methods: ["get","head"],
    url: '/palette/search',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PaletteSearchController::__invoke
* @see app/Http/Controllers/PaletteSearchController.php:12
* @route '/palette/search'
*/
PaletteSearchController.url = (options?: RouteQueryOptions) => {
    return PaletteSearchController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\PaletteSearchController::__invoke
* @see app/Http/Controllers/PaletteSearchController.php:12
* @route '/palette/search'
*/
PaletteSearchController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PaletteSearchController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PaletteSearchController::__invoke
* @see app/Http/Controllers/PaletteSearchController.php:12
* @route '/palette/search'
*/
PaletteSearchController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PaletteSearchController.url(options),
    method: 'head',
})

export default PaletteSearchController