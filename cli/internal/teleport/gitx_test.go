package teleport

import (
	"github.com/geocodio/yak/cli/internal/gitx"
	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
)

func gitxRepo(dir string) gitx.Repo { return gitx.Repo{Dir: dir, Env: testrepo.Env} }
