// Package secrets decides, from names alone, whether a file looks like it holds secrets.
package secrets

import (
	"path"
	"strings"
)

// neverFlagged overrides every other rule.
var neverFlagged = []string{
	"*.example", "*.sample", "*.example.*", "*.sample.*", ".env.template", ".env.dist",
	"*.d.ts", "*.md", "*.mdx", "*.pub", "*.crt", "*.cer",
}

// secretNames are base-name globs that are always flagged.
var secretNames = []string{
	".env*", "*.env", "*.env.*", "*.pem", "*.key", "*.p12", "*.pfx", "*.jks", "*.keystore", "*.pkcs8",
	"*.p8", "*.ppk", "*.asc", "*.gpg", "id_rsa*", "id_dsa*", "id_ecdsa*", "id_ed25519*",
	"credentials*.json", "auth.json", ".npmrc", ".pypirc", ".netrc", ".git-credentials", ".htpasswd",
	".dockercfg", ".pgpass", ".my.cnf", "service-account*.json", "client_secret*.json", "*.tfvars",
	"*.tfstate*", "*.kdbx", "kubeconfig", "secrets.yml", "secrets.yaml", "secrets.json", "secrets.*.yml",
	"vault-password*", ".vault_pass*", "*.bak", "*.bak-*", "*.orig", "*.sqlite", "*.sql.gz",
}

var nameWords = []string{"secret", "credential", "token", "passwd", "password", "apikey", "api_key", "api-key"}

var wordExtensions = map[string]bool{
	"": true, ".json": true, ".txt": true, ".yml": true, ".yaml": true, ".env": true, ".csv": true,
	".ini": true, ".conf": true, ".cfg": true, ".toml": true, ".xml": true, ".properties": true,
	".tsv": true, ".bak": true,
}

func matchAny(globs []string, name string) (string, bool) {
	for _, g := range globs {
		if ok, _ := path.Match(g, name); ok {
			return g, true
		}
	}
	return "", false
}

// IsSecretFile reports whether the path looks like a secret and why. Names only; contents are never read.
// A trailing slash marks a directory, which is checked by its own name.
func IsSecretFile(p string) (hit bool, reason string) {
	p = strings.TrimRight(strings.ReplaceAll(p, "\\", "/"), "/")
	if p == "" {
		return false, ""
	}
	base := strings.ToLower(path.Base(p))
	if _, ok := matchAny(neverFlagged, base); ok {
		return false, ""
	}
	lower := strings.ToLower(p)
	segments := strings.Split(lower, "/")
	for i, s := range segments[:len(segments)-1] {
		switch s {
		case ".ssh", ".gnupg":
			return true, s + "/*"
		case ".aws":
			if i+2 == len(segments) && segments[i+1] == "credentials" {
				return true, ".aws/credentials"
			}
		}
	}
	if g, ok := matchAny(secretNames, base); ok {
		return true, g
	}
	if wordExtensions[path.Ext(base)] {
		for _, w := range nameWords {
			if strings.Contains(base, w) {
				return true, "name contains " + w
			}
		}
	}
	return false, ""
}

// AnyInDir reports the first hit among the given paths. A collapsed directory is passed with a trailing slash
// and is checked by its name; its files are checked individually.
func AnyInDir(paths []string) (hit bool, reason string) {
	for _, p := range paths {
		if h, r := IsSecretFile(p); h {
			return true, p + ": " + r
		}
	}
	return false, ""
}
