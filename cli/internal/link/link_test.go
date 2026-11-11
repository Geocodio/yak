package link

import "testing"

func TestValidThreadID(t *testing.T) {
	for _, ok := range []string{"1", "42", "9999999999"} {
		if !ValidThreadID(ok) {
			t.Errorf("%q should be valid", ok)
		}
	}
	for _, bad := range []string{"", "0", "042", "42abc", "-1", "4 2", "../x", "10000000000", "4\n2", "٤٢", "42\n"} {
		if ValidThreadID(bad) {
			t.Errorf("%q should be invalid", bad)
		}
	}
}
