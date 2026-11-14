package session

import (
	"bytes"
	"context"
	"errors"
	"net/http"
	"testing"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/fakeyak"
)

const testSID = "4f1c0c9e-1111-4222-8333-444455556666"

type fixture struct {
	srv   *fakeyak.Server
	store Store
	ctx   context.Context
}

const threadID = 7

func newFixture(t *testing.T) *fixture {
	t.Helper()
	srv := fakeyak.New(t)
	srv.Threads[threadID] = &api.Thread{ID: threadID}
	tok := srv.AddDevice("work mac")
	srv.OpenTeleport(threadID, tok.Device.ID, "up", "open")
	client := api.New(srv.URL(), tok.Token, nil, "yak-cli/test")
	client.Backoff = func(context.Context, int) error { return nil }
	return &fixture{srv: srv, store: Store{Client: client}, ctx: context.Background()}
}

func (f *fixture) append(t *testing.T, rel string, offset int64, data string, tail string) (int64, error) {
	t.Helper()
	return f.store.Append(f.ctx, threadID, AppendRequest{SessionID: testSID, RelPath: rel, Offset: offset, Chunk: []byte(data), Tail: []byte(tail)})
}

func TestAppendFromZero(t *testing.T) {
	f := newFixture(t)
	n, err := f.append(t, testSID+".jsonl", 0, "{\"a\":1}\n{\"b\":2}\n", "")
	if err != nil || n != 16 {
		t.Fatalf("got %d, %v", n, err)
	}
	if got, _ := f.store.Length(f.ctx, threadID, testSID, testSID+".jsonl"); got != 16 {
		t.Fatalf("length %d", got)
	}
}

func TestAppendStopsAtLastNewlineAndReturnsShortLength(t *testing.T) {
	f := newFixture(t)
	n, err := f.append(t, testSID+".jsonl", 0, "one\ntwo\nthr", "")
	if err != nil || n != 8 {
		t.Fatalf("got %d, %v", n, err)
	}
	if got := string(f.srv.SessionFile(threadID, testSID, testSID+".jsonl")); got != "one\ntwo\n" {
		t.Fatalf("stored %q", got)
	}
}

func TestDuplicateRetryAccepted(t *testing.T) {
	f := newFixture(t)
	rel := testSID + ".jsonl"
	f.append(t, rel, 0, "one\ntwo\n", "")
	n, err := f.append(t, rel, 0, "one\ntwo\n", "")
	if err != nil || n != 8 {
		t.Fatalf("got %d, %v", n, err)
	}
	n, err = f.append(t, rel, 4, "two\nthree\n", "one\n")
	if err != nil || n != 14 {
		t.Fatalf("overlap: got %d, %v", n, err)
	}
}

func TestGapReturnsLengthForResume(t *testing.T) {
	f := newFixture(t)
	rel := testSID + ".jsonl"
	f.append(t, rel, 0, "one\n", "")
	_, err := f.append(t, rel, 100, "x\n", "whatever")
	var gap ErrGap
	if !errors.As(err, &gap) || gap.Length != 4 {
		t.Fatalf("got %v", err)
	}
}

func TestDivergedTailMismatch(t *testing.T) {
	f := newFixture(t)
	rel := testSID + ".jsonl"
	f.append(t, rel, 0, "one\n", "")
	if _, err := f.append(t, rel, 4, "two\n", "zzz\n"); !errors.Is(err, ErrDiverged) {
		t.Fatalf("tail: %v", err)
	}
	if _, err := f.append(t, rel, 0, "ONE\n", ""); !errors.Is(err, ErrDiverged) {
		t.Fatalf("overlap: %v", err)
	}
	if got := string(f.srv.SessionFile(threadID, testSID, rel)); got != "one\n" {
		t.Fatalf("store changed: %q", got)
	}
}

func TestImmutableFirstWriteWins(t *testing.T) {
	f := newFixture(t)
	rel := testSID + "/tool-results/a.txt"
	if stored, err := f.store.PutFile(f.ctx, threadID, testSID, rel, []byte("first")); err != nil || !stored {
		t.Fatalf("first: %v %v", stored, err)
	}
	if stored, err := f.store.PutFile(f.ctx, threadID, testSID, rel, []byte("second")); err != nil || stored {
		t.Fatalf("second: %v %v", stored, err)
	}
	got, err := f.store.Read(f.ctx, threadID, testSID, rel, 0)
	if err != nil || string(got) != "first" {
		t.Fatalf("read %q %v", got, err)
	}
	if tail, _ := f.store.Read(f.ctx, threadID, testSID, rel, 2); string(tail) != "rst" {
		t.Fatalf("offset read %q", tail)
	}
	files, err := f.store.Files(f.ctx, threadID, testSID)
	if err != nil || len(files) != 1 || files[0].Length != 5 || files[0].Mode != "immutable" {
		t.Fatalf("files %+v %v", files, err)
	}
}

func TestChunkOver1MiBIs413(t *testing.T) {
	f := newFixture(t)
	big := string(bytes.Repeat([]byte("a"), 1<<20+1))
	_, err := f.append(t, testSID+".jsonl", 0, big, "")
	if e, ok := api.AsError(err); !ok || e.Status != http.StatusRequestEntityTooLarge {
		t.Fatalf("got %v", err)
	}
}

func TestCheckpointAndNoOpenTeleport(t *testing.T) {
	f := newFixture(t)
	rel := testSID + ".jsonl"
	f.append(t, rel, 0, "one\n", "")
	if ok, n, err := f.store.Checkpoint(f.ctx, threadID, testSID, rel, hexSum([]byte("one\n"))); err != nil || !ok || n != 4 {
		t.Fatalf("match: %v %d %v", ok, n, err)
	}
	if ok, n, err := f.store.Checkpoint(f.ctx, threadID, testSID, rel, hexSum([]byte("other"))); err != nil || ok || n != 4 {
		t.Fatalf("mismatch: %v %d %v", ok, n, err)
	}
	f.srv.CloseTeleport(threadID)
	if _, err := f.store.Length(f.ctx, threadID, testSID, rel); !api.IsCode(err, "no_open_teleport") {
		t.Fatalf("got %v", err)
	}
}
