## Repositories in this thread

This thread works in more than one repository. Use absolute paths between them.

@foreach($repositories as $repository)
- `{{ $repository->sandbox_path }}`: {{ $repository->repo }} ({{ $repository->role->value }})
@endforeach

The primary repository is at `/workspace` and is where you start. Repositories marked `changes` get a pull request. Repositories marked `context` are for reading; edits there are throwaway, are discarded with the sandbox and never get a pull request.

To link one repository into another, use absolute paths, for example a composer `path` repository with `url: /extras/lib` or `npm install --no-save /extras/lib`. Never commit those local links, and never push from a `context` repository.
