@if($situation === 'failing')
The thread preview did not start: the dev server is not answering. Find out why and fix it.
@else
This repository has no `.yak/preview.yml`, so the thread preview cannot run the app. Write one.
@endif


@include('prompts.partials.preview-manifest-schema')

The preview opens `http://<sandbox>:<port><health_probe_path>` once the app answers 2xx. The `yak` CLI is not available in this sandbox, so verify with plain shell tools (`bash`, `curl`).

## Definition of done

Do not finish until every step below has passed:

1. Write or correct `.yak/preview.yml` with the exact key names above.
2. Run the `cold_start` command in this sandbox exactly as written, through `bash -lc` from the home directory.
3. Request `http://127.0.0.1:<port><health_probe_path>` with `curl -i` and confirm it answers 2xx.
4. If anything fails, read the dev server output, fix the file or the command, and repeat steps 2 and 3 until the app answers.
5. Commit the file, then report the command you ran and the status code you saw. If you cannot get the app to answer, say exactly what blocks it instead of guessing.
