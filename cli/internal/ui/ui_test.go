package ui

import (
	"bytes"
	"reflect"
	"strings"
	"testing"
)

func term(input string) (Prompter, *bytes.Buffer) {
	var out bytes.Buffer
	return NewTerminal(strings.NewReader(input), &out), &out
}

func TestTerminalPrompterDefaultsOnEnter(t *testing.T) {
	p, _ := term("\n\n\n\n")
	if ok, err := p.Confirm("Go?", true); err != nil || !ok {
		t.Fatal(ok, err)
	}
	if ok, _ := p.Confirm("Include anyway?", false); ok {
		t.Fatal("default no must stay no")
	}
	i, err := p.Choose("Which?", []Option{{Label: "a"}, {Label: "b"}}, 1)
	if err != nil || i != 1 {
		t.Fatal(i, err)
	}
	if s, _ := p.Edit("Name", "box"); s != "box" {
		t.Fatal(s)
	}
}

func TestConfirmAnswers(t *testing.T) {
	p, _ := term("y\nNo\nmaybe\ny\n")
	if ok, _ := p.Confirm("q", false); !ok {
		t.Fatal("y")
	}
	if ok, _ := p.Confirm("q", true); ok {
		t.Fatal("No")
	}
	if ok, _ := p.Confirm("q", false); !ok {
		t.Fatal("invalid answer reprompts, then y")
	}
}

func TestChooseByNumber(t *testing.T) {
	p, _ := term("9\n2\n")
	i, _ := p.Choose("Which?", []Option{{Label: "a"}, {Label: "b", Hint: "h"}}, 0)
	if i != 1 {
		t.Fatal(i)
	}
}

func TestPickTogglesWithX(t *testing.T) {
	p, out := term("x 1\nx 2\nx 3\nx 3\n\n")
	got, err := p.Pick("Files", []PickItem{{Label: "a", Selected: true}, {Label: "b"}, {Label: "c"}})
	if err != nil || !reflect.DeepEqual(got, []int{1}) {
		t.Fatalf("%v %v", got, err)
	}
	if !strings.Contains(out.String(), "[x]") {
		t.Fatal(out.String())
	}
}

func TestEOFTakesDefaults(t *testing.T) {
	p, _ := term("")
	if ok, err := p.Confirm("q", true); err != nil || !ok {
		t.Fatal(ok, err)
	}
}

func TestNonInteractiveReturnsDefaults(t *testing.T) {
	p := NewNonInteractive()
	if ok, _ := p.Confirm("q", true); !ok {
		t.Fatal()
	}
	if i, _ := p.Choose("q", []Option{{}, {}}, 1); i != 1 {
		t.Fatal(i)
	}
	if got, _ := p.Pick("q", []PickItem{{Selected: true}, {}, {Selected: true}}); !reflect.DeepEqual(got, []int{0, 2}) {
		t.Fatal(got)
	}
	if s, _ := p.Edit("l", "cur"); s != "cur" {
		t.Fatal(s)
	}
}

func TestOutputHelpers(t *testing.T) {
	var b bytes.Buffer
	OK(&b, "done %d", 1)
	Fail(&b, "bad")
	Warn(&b, "hmm")
	if b.String() != "✓ done 1\n✗ bad\n! hmm\n" {
		t.Fatalf("%q", b.String())
	}
}
