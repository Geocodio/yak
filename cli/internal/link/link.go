// Package link handles yak:// links and the thread ids they carry.
package link

// ValidThreadID reports whether s is a numeric thread id: 1 to 10 digits, no leading zero, nothing else.
func ValidThreadID(s string) bool {
	if len(s) == 0 || len(s) > 10 || s[0] == '0' {
		return false
	}
	for i := 0; i < len(s); i++ {
		if s[i] < '0' || s[i] > '9' {
			return false
		}
	}
	return true
}
