import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
import traceEfd393 from './trace'
import issues from './issues'
import monitorsB72772 from './monitors'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\BootstrapController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/BootstrapController.php:40
* @route '/telemetry-ui/api/v2/bootstrap'
*/
export const bootstrap = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: bootstrap.url(options),
    method: 'get',
})

bootstrap.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/bootstrap',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\BootstrapController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/BootstrapController.php:40
* @route '/telemetry-ui/api/v2/bootstrap'
*/
bootstrap.url = (options?: RouteQueryOptions) => {
    return bootstrap.definition.url + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\BootstrapController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/BootstrapController.php:40
* @route '/telemetry-ui/api/v2/bootstrap'
*/
bootstrap.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: bootstrap.url(options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\BootstrapController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/BootstrapController.php:40
* @route '/telemetry-ui/api/v2/bootstrap'
*/
bootstrap.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: bootstrap.url(options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ViewStateController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ViewStateController.php:21
* @route '/telemetry-ui/api/v2/view-state'
*/
export const viewState = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: viewState.url(options),
    method: 'post',
})

viewState.definition = {
    methods: ["post"],
    url: '/telemetry-ui/api/v2/view-state',
} satisfies RouteDefinition<["post"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ViewStateController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ViewStateController.php:21
* @route '/telemetry-ui/api/v2/view-state'
*/
viewState.url = (options?: RouteQueryOptions) => {
    return viewState.definition.url + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ViewStateController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ViewStateController.php:21
* @route '/telemetry-ui/api/v2/view-state'
*/
viewState.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: viewState.url(options),
    method: 'post',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PageController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PageController.php:24
* @route '/telemetry-ui/api/v2/pages/{page}'
*/
export const page = (args: { page: string | number } | [page: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: page.url(args, options),
    method: 'get',
})

page.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/pages/{page}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PageController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PageController.php:24
* @route '/telemetry-ui/api/v2/pages/{page}'
*/
page.url = (args: { page: string | number } | [page: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { page: args }
    }

    if (Array.isArray(args)) {
        args = {
            page: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        page: args.page,
    }

    return page.definition.url
            .replace('{page}', parsedArgs.page.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PageController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PageController.php:24
* @route '/telemetry-ui/api/v2/pages/{page}'
*/
page.get = (args: { page: string | number } | [page: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: page.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PageController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PageController.php:24
* @route '/telemetry-ui/api/v2/pages/{page}'
*/
page.head = (args: { page: string | number } | [page: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: page.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PanelController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PanelController.php:24
* @route '/telemetry-ui/api/v2/panels/{panel}'
*/
export const panel = (args: { panel: string | number } | [panel: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: panel.url(args, options),
    method: 'get',
})

panel.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/panels/{panel}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PanelController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PanelController.php:24
* @route '/telemetry-ui/api/v2/panels/{panel}'
*/
panel.url = (args: { panel: string | number } | [panel: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { panel: args }
    }

    if (Array.isArray(args)) {
        args = {
            panel: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        panel: args.panel,
    }

    return panel.definition.url
            .replace('{panel}', parsedArgs.panel.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PanelController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PanelController.php:24
* @route '/telemetry-ui/api/v2/panels/{panel}'
*/
panel.get = (args: { panel: string | number } | [panel: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: panel.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PanelController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PanelController.php:24
* @route '/telemetry-ui/api/v2/panels/{panel}'
*/
panel.head = (args: { panel: string | number } | [panel: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: panel.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ExploreController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ExploreController.php:34
* @route '/telemetry-ui/api/v2/explore/{signal}'
*/
export const explore = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: explore.url(args, options),
    method: 'get',
})

explore.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/explore/{signal}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ExploreController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ExploreController.php:34
* @route '/telemetry-ui/api/v2/explore/{signal}'
*/
explore.url = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { signal: args }
    }

    if (Array.isArray(args)) {
        args = {
            signal: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        signal: args.signal,
    }

    return explore.definition.url
            .replace('{signal}', parsedArgs.signal.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ExploreController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ExploreController.php:34
* @route '/telemetry-ui/api/v2/explore/{signal}'
*/
explore.get = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: explore.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ExploreController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ExploreController.php:34
* @route '/telemetry-ui/api/v2/explore/{signal}'
*/
explore.head = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: explore.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\FacetsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/FacetsController.php:29
* @route '/telemetry-ui/api/v2/facets/{signal}'
*/
export const facets = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: facets.url(args, options),
    method: 'get',
})

facets.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/facets/{signal}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\FacetsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/FacetsController.php:29
* @route '/telemetry-ui/api/v2/facets/{signal}'
*/
facets.url = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { signal: args }
    }

    if (Array.isArray(args)) {
        args = {
            signal: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        signal: args.signal,
    }

    return facets.definition.url
            .replace('{signal}', parsedArgs.signal.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\FacetsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/FacetsController.php:29
* @route '/telemetry-ui/api/v2/facets/{signal}'
*/
facets.get = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: facets.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\FacetsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/FacetsController.php:29
* @route '/telemetry-ui/api/v2/facets/{signal}'
*/
facets.head = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: facets.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::entities
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:25
* @route '/telemetry-ui/api/v2/entities/{type}'
*/
export const entities = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: entities.url(args, options),
    method: 'get',
})

entities.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/entities/{type}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::entities
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:25
* @route '/telemetry-ui/api/v2/entities/{type}'
*/
entities.url = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { type: args }
    }

    if (Array.isArray(args)) {
        args = {
            type: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        type: args.type,
    }

    return entities.definition.url
            .replace('{type}', parsedArgs.type.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::entities
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:25
* @route '/telemetry-ui/api/v2/entities/{type}'
*/
entities.get = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: entities.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::entities
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:25
* @route '/telemetry-ui/api/v2/entities/{type}'
*/
entities.head = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: entities.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::entity
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:46
* @route '/telemetry-ui/api/v2/entities/{type}/story'
*/
export const entity = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: entity.url(args, options),
    method: 'get',
})

entity.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/entities/{type}/story',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::entity
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:46
* @route '/telemetry-ui/api/v2/entities/{type}/story'
*/
entity.url = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { type: args }
    }

    if (Array.isArray(args)) {
        args = {
            type: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        type: args.type,
    }

    return entity.definition.url
            .replace('{type}', parsedArgs.type.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::entity
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:46
* @route '/telemetry-ui/api/v2/entities/{type}/story'
*/
entity.get = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: entity.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::entity
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:46
* @route '/telemetry-ui/api/v2/entities/{type}/story'
*/
entity.head = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: entity.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:38
* @route '/telemetry-ui/api/v2/traces/{traceId}'
*/
export const trace = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: trace.url(args, options),
    method: 'get',
})

trace.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/traces/{traceId}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:38
* @route '/telemetry-ui/api/v2/traces/{traceId}'
*/
trace.url = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { traceId: args }
    }

    if (Array.isArray(args)) {
        args = {
            traceId: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        traceId: args.traceId,
    }

    return trace.definition.url
            .replace('{traceId}', parsedArgs.traceId.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:38
* @route '/telemetry-ui/api/v2/traces/{traceId}'
*/
trace.get = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: trace.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:38
* @route '/telemetry-ui/api/v2/traces/{traceId}'
*/
trace.head = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: trace.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ErrorGroupController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ErrorGroupController.php:33
* @route '/telemetry-ui/api/v2/errors/{group}'
*/
export const error = (args: { group: string | number } | [group: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: error.url(args, options),
    method: 'get',
})

error.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/errors/{group}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ErrorGroupController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ErrorGroupController.php:33
* @route '/telemetry-ui/api/v2/errors/{group}'
*/
error.url = (args: { group: string | number } | [group: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { group: args }
    }

    if (Array.isArray(args)) {
        args = {
            group: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        group: args.group,
    }

    return error.definition.url
            .replace('{group}', parsedArgs.group.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ErrorGroupController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ErrorGroupController.php:33
* @route '/telemetry-ui/api/v2/errors/{group}'
*/
error.get = (args: { group: string | number } | [group: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: error.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ErrorGroupController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ErrorGroupController.php:33
* @route '/telemetry-ui/api/v2/errors/{group}'
*/
error.head = (args: { group: string | number } | [group: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: error.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::issue
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:24
* @route '/telemetry-ui/api/v2/issues/{id}'
*/
export const issue = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: issue.url(args, options),
    method: 'get',
})

issue.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/issues/{id}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::issue
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:24
* @route '/telemetry-ui/api/v2/issues/{id}'
*/
issue.url = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { id: args }
    }

    if (Array.isArray(args)) {
        args = {
            id: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        id: args.id,
    }

    return issue.definition.url
            .replace('{id}', parsedArgs.id.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::issue
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:24
* @route '/telemetry-ui/api/v2/issues/{id}'
*/
issue.get = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: issue.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::issue
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:24
* @route '/telemetry-ui/api/v2/issues/{id}'
*/
issue.head = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: issue.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\AnnotationsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/AnnotationsController.php:22
* @route '/telemetry-ui/api/v2/annotations'
*/
export const annotations = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: annotations.url(options),
    method: 'get',
})

annotations.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/annotations',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\AnnotationsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/AnnotationsController.php:22
* @route '/telemetry-ui/api/v2/annotations'
*/
annotations.url = (options?: RouteQueryOptions) => {
    return annotations.definition.url + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\AnnotationsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/AnnotationsController.php:22
* @route '/telemetry-ui/api/v2/annotations'
*/
annotations.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: annotations.url(options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\AnnotationsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/AnnotationsController.php:22
* @route '/telemetry-ui/api/v2/annotations'
*/
annotations.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: annotations.url(options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::monitors
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:28
* @route '/telemetry-ui/api/v2/monitors'
*/
export const monitors = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: monitors.url(options),
    method: 'get',
})

monitors.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/monitors',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::monitors
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:28
* @route '/telemetry-ui/api/v2/monitors'
*/
monitors.url = (options?: RouteQueryOptions) => {
    return monitors.definition.url + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::monitors
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:28
* @route '/telemetry-ui/api/v2/monitors'
*/
monitors.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: monitors.url(options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\MonitorsController::monitors
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/MonitorsController.php:28
* @route '/telemetry-ui/api/v2/monitors'
*/
monitors.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: monitors.url(options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\DimensionLabelsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/DimensionLabelsController.php:27
* @route '/telemetry-ui/api/v2/dimensions/labels'
*/
export const dimensionLabels = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: dimensionLabels.url(options),
    method: 'get',
})

dimensionLabels.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/dimensions/labels',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\DimensionLabelsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/DimensionLabelsController.php:27
* @route '/telemetry-ui/api/v2/dimensions/labels'
*/
dimensionLabels.url = (options?: RouteQueryOptions) => {
    return dimensionLabels.definition.url + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\DimensionLabelsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/DimensionLabelsController.php:27
* @route '/telemetry-ui/api/v2/dimensions/labels'
*/
dimensionLabels.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: dimensionLabels.url(options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\DimensionLabelsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/DimensionLabelsController.php:27
* @route '/telemetry-ui/api/v2/dimensions/labels'
*/
dimensionLabels.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: dimensionLabels.url(options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\StreamController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/StreamController.php:30
* @route '/telemetry-ui/api/v2/stream/{signal}'
*/
export const stream = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: stream.url(args, options),
    method: 'get',
})

stream.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/stream/{signal}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\StreamController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/StreamController.php:30
* @route '/telemetry-ui/api/v2/stream/{signal}'
*/
stream.url = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { signal: args }
    }

    if (Array.isArray(args)) {
        args = {
            signal: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        signal: args.signal,
    }

    return stream.definition.url
            .replace('{signal}', parsedArgs.signal.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\StreamController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/StreamController.php:30
* @route '/telemetry-ui/api/v2/stream/{signal}'
*/
stream.get = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: stream.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\StreamController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/StreamController.php:30
* @route '/telemetry-ui/api/v2/stream/{signal}'
*/
stream.head = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: stream.url(args, options),
    method: 'head',
})

const api = {
    bootstrap: Object.assign(bootstrap, bootstrap),
    viewState: Object.assign(viewState, viewState),
    page: Object.assign(page, page),
    panel: Object.assign(panel, panel),
    explore: Object.assign(explore, explore),
    facets: Object.assign(facets, facets),
    entities: Object.assign(entities, entities),
    entity: Object.assign(entity, entity),
    trace: Object.assign(trace, traceEfd393),
    error: Object.assign(error, error),
    issue: Object.assign(issue, issue),
    issues: Object.assign(issues, issues),
    annotations: Object.assign(annotations, annotations),
    monitors: Object.assign(monitors, monitorsB72772),
    dimensionLabels: Object.assign(dimensionLabels, dimensionLabels),
    stream: Object.assign(stream, stream),
}

export default api